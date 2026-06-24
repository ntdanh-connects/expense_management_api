<?php

namespace App\Services;

use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Notifications\Auth\VerifyEmailNotification;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;

use function Illuminate\Support\now;

class UserService
{
    protected $userRepository;
    protected $imageUploadService;

    public function __construct(UserRepositoryInterface $userRepository, ImageUploadService $imageUploadService)
    {
        $this->userRepository = $userRepository;
        $this->imageUploadService = $imageUploadService;
    }

    public function registerUser(array $data)
    {
        return DB::transaction(function() use ($data){
            $uuid = (string) Str::uuid7();

            $user = $this->userRepository->create([
                'user_id' => $uuid,
                'email' => $data['email'],
                'status' => 'active'
            ]);

            $user->credential()->create([
                'password_hash' => Hash::make($data['password'])
            ]);
            
            $user->profile()->create([
                'full_name' => $data['full_name'],
                'created_at' => now()
            ]);

            $user->preference()->create([
                'language'            => 'vi',
                'theme'               => 'light',
                'currency'            => 'VND',
                'timezone'            => 'Asia/Ho_Chi_Minh',
                'financial_start_day' => 1,
                'created_at'          => now()
            ]);

            $this->createDefaultCashWallet($uuid);
            
            $user->notify(new VerifyEmailNotification($user));

            return $user;
        });
    }

    public function loginUser(array $data, array $deviceData){
        $user = $this->userRepository->findByEmail($data['email']);

        if(!$user || !Hash::check($data['password'], $user->credential->password_hash)){
            throw new \Exception(__('messages.email_password_incorrect'));
        }

        if($user->status === 'suspended'){
            throw new \Exception(__('messages.user_suspended'));
        }

        if(is_null($user->email_verified_at)){
            throw new \Exception(__('messages.email_not_verified'));
        }

        // Thu hồi toàn bộ các phiên làm việc đang hoạt động của người dùng trước khi cấp phiên mới
        $this->logoutAllDevices($user->user_id);

        $accessToken = Str::random(60);
        $refreshToken = Str::random(60);

        $existingSession = $user->sessions()->where(
            'user_agent', $deviceData['user_agent']
        )->first();

        if($existingSession){
            $existingSession->update([
                'refresh_token_hash'      => hash('sha256', $refreshToken),
                'access_token_hash'       => hash('sha256', $accessToken),
                'access_token_expired_at' => now()->addMinutes(15),
                'ip_address'              => $deviceData['ip_address'],
                'expired_at'              => now()->addDays(30),
                'revoked_at'              => null // Khởi động lại phiên làm việc (kích hoạt lại từ revoked sang active)
            ]);
        }else{
             $user->sessions()->create([
            'id'                      => (string) Str::uuid7(),
            'refresh_token_hash'      => hash('sha256', $refreshToken),
            'access_token_hash'       => hash('sha256', $accessToken),
            'access_token_expired_at' => now()->addMinutes(15),
            'device_type'             => $deviceData['device_type'],
            'device_name'             => $deviceData['device_name'],
            'ip_address'              => $deviceData['ip_address'],
            'user_agent'              => $deviceData['user_agent'],
            'expired_at'              => now()->addDays(30),
            'created_at'              => now()
            ]);
        }
        return [
            'user'          => $user,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken
        ];
    }

    public function refreshToken(string $userId, string $refreshToken){
        $hashedToken = hash('sha256',$refreshToken);

        $session = $this->userRepository->findSessionbyToken($userId,$hashedToken);

        if(!$session){
            throw new \Exception(__('messages.session_expired_invalid_code'));
        }

        $newAccessToken = Str::random(60);
        $newRefreshToken = Str::random(60);

        DB::table('user_sessions')->where('id',$session->id)->update([
            'refresh_token_hash'      => hash('sha256',$newRefreshToken),
            'access_token_hash'       => hash('sha256',$newAccessToken),
            'access_token_expired_at' => now()->addMinutes(15),
            'expired_at'              => now()->addDays(30)
        ]);

        $user = $this->userRepository->find($userId);

        return [
            'user'=> $user,
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken
        ];
    }

    public function getUserProfile(string $userId)
    {
        $user = $this->userRepository->findWithRelations($userId);

        if (!$user) {
            throw new \Exception(__('messages.user_not_found'));
        }

        if ($user->status === 'suspended') {
            throw new \Exception(__('messages.user_locked'));
        }

        return $user;
    }

    public function socialLogin(string $provider, string $token, array $deviceData, string $redirectUri = null)
    {
        $provider = strtolower($provider);
        if ($provider !== 'google' && $provider !== 'github') {
            throw new \Exception(__('messages.provider_not_supported'));
        }

        $socialUser = $this->verifySocialToken($provider, $token, $redirectUri);
        $providerId = $socialUser['id'];
        $email = $socialUser['email'];
        $fullName = $socialUser['name'] ?? 'Social User';
        $avatarUrl = $socialUser['avatar_url'] ?? null;

        if (!$email) {
            throw new \Exception(__('messages.cannot_get_email_from_provider', ['provider' => $provider]));
        }

        // 1. Kiểm tra xem đã có user nào liên kết với social ID này chưa
        $columnName = $provider . '_id'; // google_id or github_id
        $user = \App\Models\User::where($columnName, $providerId)->first();

        if ($user) {
            // Đã liên kết trước đó -> Đăng nhập luôn!
            if ($user->status === 'suspended') {
                throw new \Exception(__('messages.user_suspended'));
            }
            return $this->generateUserSession($user, $deviceData);
        }

        // 2. Nếu chưa liên kết social ID, kiểm tra trùng email
        $userWithEmail = $this->userRepository->findByEmail($email);

        if ($userWithEmail) {
            // Đã tồn tại tài khoản có email này (đăng ký thủ công hoặc MXH khác)
            // Kích hoạt Safe Account Linking!
            if ($userWithEmail->status === 'suspended') {
                throw new \Exception(__('messages.user_locked'));
            }

            // Tạo link_token bảo mật, mã hóa toàn bộ dữ liệu MXH
            $linkPayload = [
                'email' => $email,
                'provider' => $provider,
                'provider_id' => $providerId,
                'full_name' => $fullName,
                'avatar_url' => $avatarUrl,
                'timestamp' => now()->timestamp,
            ];

            $linkToken = \Illuminate\Support\Facades\Crypt::encrypt($linkPayload);

            return [
                'status' => 'requires_linking',
                'message' => 'Email này đã được đăng ký bằng mật khẩu trước đó. Vui lòng xác thực mật khẩu để liên kết tài khoản.',
                'link_token' => $linkToken,
                'email' => $email
            ];
        }

        // 3. Email chưa tồn tại -> Tạo tài khoản mới tự động
        return DB::transaction(function() use ($provider, $providerId, $email, $fullName, $avatarUrl, $deviceData) {
            $uuid = (string) Str::uuid7();

            $user = $this->userRepository->create([
                'user_id' => $uuid,
                'email' => $email,
                'status' => 'active',
                'email_verified_at' => now(), // MXH mặc định coi như email đã verified
                $provider . '_id' => $providerId
            ]);

            $user->profile()->create([
                'full_name' => $fullName,
                'avatar_url' => $avatarUrl,
                'created_at' => now()
            ]);

            $user->preference()->create([
                'language'            => 'vi',
                'theme'               => 'light',
                'currency'            => 'VND',
                'timezone'            => 'Asia/Ho_Chi_Minh',
                'financial_start_day' => 1,
                'created_at'          => now()
            ]);

            $this->createDefaultCashWallet($uuid);

            return $this->generateUserSession($user, $deviceData);
        });
    }

    public function linkSocialAccount(string $linkToken, string $password, array $deviceData)
    {
        try {
            $payload = \Illuminate\Support\Facades\Crypt::decrypt($linkToken);
        } catch (\Exception $e) {
            throw new \Exception(__('messages.link_token_invalid'));
        }

        // Token chỉ có hiệu lực trong 10 phút
        if (now()->timestamp - $payload['timestamp'] > 600) {
            throw new \Exception(__('messages.link_session_expired'));
        }

        $email = $payload['email'];
        $provider = $payload['provider'];
        $providerId = $payload['provider_id'];
        $avatarUrl = $payload['avatar_url'];

        $user = $this->userRepository->findByEmail($email);
        if (!$user) {
            throw new \Exception(__('messages.user_not_found_link'));
        }

        if (!$user->credential || !Hash::check($password, $user->credential->password_hash)) {
            throw new \Exception(__('messages.confirm_password_incorrect'));
        }

        // Cập nhật liên kết social ID trực tiếp trong bảng users
        $columnName = $provider . '_id';
        
        // Đảm bảo social ID này chưa bị liên kết bởi tài khoản khác (tránh xung đột)
        $exists = \App\Models\User::where($columnName, $providerId)->where('user_id', '!=', $user->user_id)->exists();
        if ($exists) {
            throw new \Exception(__('messages.provider_already_linked', ['provider' => $provider]));
        }

        $user->update([
            $columnName => $providerId,
            'email_verified_at' => $user->email_verified_at ?? now() // Nếu chưa kích hoạt email thì kích hoạt luôn
        ]);

        // Cập nhật avatar từ mạng xã hội nếu user chưa có avatar
        if ($avatarUrl && !$user->profile->avatar_url) {
            $user->profile->update(['avatar_url' => $avatarUrl]);
        }

        return $this->generateUserSession($user, $deviceData);
    }

    private function verifySocialToken(string $provider, string $token, string $redirectUri = null): array
    {
        // === Mock Token cho Dev Mode ===
        if (str_starts_with($token, 'mock_')) {
            $parts = explode('_', $token, 3); // mock_google_email@test.com
            $mockEmail = $parts[2] ?? 'dev@mock.com';
            return [
                'id' => 'mock_' . md5($mockEmail),
                'email' => $mockEmail,
                'name' => 'Dev User (' . $mockEmail . ')',
                'avatar_url' => null,
            ];
        }

        // === Google: Verify ID Token (JWT) ===
        if ($provider === 'google') {
            // Gọi Google tokeninfo API để xác minh ID Token
            $response = Http::timeout(5)->get("https://oauth2.googleapis.com/tokeninfo", [
                'id_token' => $token,
            ]);

            if ($response->failed()) {
                \Illuminate\Support\Facades\Log::error('[Social-Auth] Google tokeninfo failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception(__('messages.google_token_invalid', [], 'Google ID Token không hợp lệ hoặc đã hết hạn!'));
            }

            $data = $response->json();

            // Log audience để debug (Google ID Token từ Android có aud = Web Client ID)
            \Illuminate\Support\Facades\Log::info('[Social-Auth] Google token verified', [
                'aud' => $data['aud'] ?? 'null',
                'email' => $data['email'] ?? 'null',
            ]);

            // Kiểm tra audience (không bắt buộc vì Google API đã verify token)
            $googleClientId = config('services.google.client_id');
            if ($googleClientId && $googleClientId !== 'your-google-client-id' && ($data['aud'] ?? '') !== $googleClientId) {
                \Illuminate\Support\Facades\Log::warning('[Social-Auth] Google aud mismatch - hãy set GOOGLE_CLIENT_ID = ' . ($data['aud'] ?? 'null'), [
                    'expected' => $googleClientId,
                    'got' => $data['aud'] ?? 'null',
                ]);
                // Không throw exception - Google API đã xác minh token hợp lệ rồi
            }

            return [
                'id' => $data['sub'], // Google unique user ID
                'email' => $data['email'] ?? null,
                'name' => $data['name'] ?? ($data['given_name'] ?? 'Google User'),
                'avatar_url' => $data['picture'] ?? null,
            ];
        }

        // === GitHub: Exchange code → Access Token → Get user info ===
        if ($provider === 'github') {
            $accessToken = $token;

            if (!str_starts_with($token, 'gho_') && !str_starts_with($token, 'ghp_')) {
                // Kiểm tra xem redirectUri có chứa tên miền Web (localhost hoặc onrender) không
                $isWebRedirect = $redirectUri && (
                    str_contains($redirectUri, 'localhost') || 
                    str_contains($redirectUri, 'onrender.com')
                );
                // Nếu là Web, dùng bộ khóa github_web. Ngược lại dùng bộ khóa mặc định (Mobile)
                $clientId = ($isWebRedirect && config('services.github_web.client_id'))
                    ? config('services.github_web.client_id')
                    : config('services.github.client_id');
                $clientSecret = ($isWebRedirect && config('services.github_web.client_secret'))
                    ? config('services.github_web.client_secret')
                    : config('services.github.client_secret');

                $exchangeParams = [
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'code'          => $token,
                ];
                
                // Nếu Frontend có gửi sang redirect_uri, ta phải gửi kèm theo đúng chuẩn OAuth2
                if ($redirectUri) {
                    $exchangeParams['redirect_uri'] = $redirectUri;
                }

                $exchangeResponse = Http::asJson()->acceptJson()->timeout(5)->post("https://github.com/login/oauth/access_token", $exchangeParams);

                if ($exchangeResponse->successful() && $exchangeResponse->json('access_token')) {
                    $accessToken = $exchangeResponse->json('access_token');
                } else {
                    throw new \Exception(__('messages.github_exchange_failed', ['error' => ($exchangeResponse->json('error_description') ?? "Unknown error")]));
                }
            }

            // GitHub sử dụng Access Token để lấy user info
            $response = Http::withToken($accessToken)->timeout(5)->get("https://api.github.com/user");
            
            if ($response->failed()) {
                throw new \Exception(__('messages.github_token_invalid'));
            }

            $email = $response->json('email');
            
            // Trường hợp email bị ẩn (private) trên GitHub
            if (!$email) {
                $emailsResponse = Http::withToken($accessToken)->timeout(5)->get("https://api.github.com/user/emails");
                if ($emailsResponse->successful()) {
                    foreach ($emailsResponse->json() as $emailData) {
                        if ($emailData['primary'] && $emailData['verified']) {
                            $email = $emailData['email'];
                            break;
                        }
                    }
                }
            }

            return [
                'id' => (string) $response->json('id'),
                'email' => $email,
                'name' => $response->json('name') ?? $response->json('login'),
                'avatar_url' => $response->json('avatar_url'),
            ];
        }

        throw new \Exception(__('messages.provider_not_supported'));
    }

    private function generateUserSession($user, array $deviceData): array
    {
        // Thu hồi toàn bộ các phiên làm việc đang hoạt động của người dùng trước khi cấp phiên mới
        $this->logoutAllDevices($user->user_id);

        $accessToken = Str::random(60);
        $refreshToken = Str::random(60);

        $existingSession = $user->sessions()->where(
            'user_agent', $deviceData['user_agent']
        )->first();

        if ($existingSession) {
            $existingSession->update([
                'refresh_token_hash'      => hash('sha256', $refreshToken),
                'access_token_hash'       => hash('sha256', $accessToken),
                'access_token_expired_at' => now()->addMinutes(15),
                'ip_address'              => $deviceData['ip_address'],
                'expired_at'              => now()->addDays(30),
                'revoked_at'              => null
            ]);
        } else {
            $user->sessions()->create([
                'id'                      => (string) Str::uuid7(),
                'refresh_token_hash'      => hash('sha256', $refreshToken),
                'access_token_hash'       => hash('sha256', $accessToken),
                'access_token_expired_at' => now()->addMinutes(15),
                'device_type'             => $deviceData['device_type'],
                'device_name'             => $deviceData['device_name'],
                'ip_address'              => $deviceData['ip_address'],
                'user_agent'              => $deviceData['user_agent'],
                'expired_at'              => now()->addDays(30),
                'created_at'              => now()
            ]);
        }

        return [
            'user'          => $user,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken
        ];
    }

    public function logoutCurrentDevice(string $plainAccessToken): void{
        $hashedToken = hash('sha256',$plainAccessToken);
        
        DB::table('user_sessions')
        ->where('access_token_hash',$hashedToken)
        ->whereNull('revoked_at')
        ->update([
            'revoked_at' => now()
        ]);
    }

    public function logoutAllDevices(string $userId):void{
        DB::table('user_sessions')
        ->where('user_id',$userId)
        ->whereNull('revoked_at')
        ->update([
            'revoked_at' => now()
        ]);
    }

    /**
     * Cập nhật ảnh đại diện lên S3 và cập nhật URL vào user_profiles
     */
    public function updateAvatar(string $userId, UploadedFile $file)
    {
        return DB::transaction(function () use ($userId, $file) {
            // 1. Tìm thông tin profile của user
            $user = $this->userRepository->find($userId);
            if (!$user) {
                throw new \Exception(__('messages.user_not_found'));
            }
            
            $profile = $user->profile;
            if (!$profile) {
                throw new \Exception(__('messages.profile_not_found'));
            }

            $oldAvatarUrl = $profile->avatar_url;

            // 2. Upload ảnh mới lên S3
            $newAvatarUrl = $this->imageUploadService->uploadToS3($file, 'avatars');

            // 3. Cập nhật vào DB
            $profile->update([
                'avatar_url' => $newAvatarUrl
            ]);

            // 4. Xóa ảnh cũ trên S3 để tối ưu dung lượng (nếu có)
            if ($oldAvatarUrl) {
                $this->imageUploadService->deleteFromS3($oldAvatarUrl);
            }

            return $user->load('profile', 'preference');
        });
    }

    /**
     * Cập nhật thông tin cá nhân (họ tên) & Cài đặt (đơn vị tiền tệ, múi giờ, giao diện, ngôn ngữ)
     */
    public function updateProfile(string $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            $user = $this->userRepository->find($userId);
            if (!$user) {
                throw new \Exception(__('messages.user_not_found'));
            }

            // 1. Cập nhật thông tin profile (họ tên)
            if (isset($data['full_name'])) {
                $profile = $user->profile;
                if (!$profile) {
                    throw new \Exception(__('messages.profile_not_found'));
                }
                $profile->update([
                    'full_name' => $data['full_name']
                ]);
            }

            // 2. Cập nhật thông tin preferences (đơn vị tiền tệ, múi giờ, v.v.)
            $preferenceFields = ['currency', 'timezone', 'theme', 'language', 'financial_start_day'];
            $preferenceData = array_intersect_key($data, array_flip($preferenceFields));

            if (!empty($preferenceData)) {
                $preference = $user->preference;
                $oldCurrency = $preference ? $preference->currency : 'VND';
                $oldFinancialStartDay = $preference ? $preference->financial_start_day : 1;

                if (!$preference) {
                    $preference = $user->preference()->create(array_merge([
                        'language'            => 'vi',
                        'theme'               => 'light',
                        'currency'            => 'VND',
                        'timezone'            => 'Asia/Ho_Chi_Minh',
                        'financial_start_day' => 1,
                        'created_at'          => now()
                    ], $preferenceData));
                    $oldCurrency = 'VND';
                } else {
                    $preference->update($preferenceData);
                }

                $newCurrency = $preferenceData['currency'] ?? null;
                if ($newCurrency && strtoupper($newCurrency) !== strtoupper($oldCurrency)) {
                    // Dispatch Job chạy ngầm để tính toán lại tỷ giá và ngân sách theo đồng tiền mới
                    \App\Jobs\RecalculateUserCurrenciesJob::dispatch($userId, $newCurrency);
                }

                $newFinancialStartDay = $preferenceData['financial_start_day'] ?? null;
                if ($newFinancialStartDay && (int)$newFinancialStartDay !== (int)$oldFinancialStartDay) {
                    // Tính toán lại tất cả các ngân sách của user theo chu kỳ tài chính mới
                    $budgets = \App\Models\Budget::where('user_id', $userId)->get();
                    $budgetService = app(\App\Services\BudgetService::class);
                    foreach ($budgets as $budget) {
                        $budgetService->recalculateSingleBudget($budget);
                    }
                    \Illuminate\Support\Facades\Cache::increment("user_{$userId}_report_version");
                }
            }

            return $user->load('profile', 'preference');
        });
    }

    /**
     * Xoá vĩnh viễn tài khoản người dùng và toàn bộ dữ liệu liên quan trên hệ thống
     */
    public function deleteUserAccount(string $userId): void
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new \Exception(__('messages.user_not_found'));
        }

        // 1. Xoá ảnh đại diện trên S3 trước (nếu có)
        $profile = $user->profile;
        if ($profile && $profile->avatar_url) {
            try {
                $this->imageUploadService->deleteFromS3($profile->avatar_url);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Không thể xoá avatar của user {$userId} trên S3 khi xoá tài khoản: " . $e->getMessage());
            }
        }

        // 2. Sử dụng DB Transaction kết hợp trì hoãn ràng buộc để dọn sạch Database
        DB::transaction(function () use ($userId) {
            // Tận dụng cơ chế DEFERRABLE của các khoá ngoại để tắt kiểm tra ràng buộc tạm thời trong transaction này
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            // Xoá các bảng thống kê và phụ trợ
            DB::table('daily_statistics')->where('user_id', $userId)->delete();
            DB::table('monthly_statistics')->where('user_id', $userId)->delete();
            DB::table('category_statistics')->where('user_id', $userId)->delete();
            DB::table('report_exports')->where('user_id', $userId)->delete();
            DB::table('import_jobs')->where('user_id', $userId)->delete();
            DB::table('transaction_audits')->where('changed_by', $userId)->delete();

            // Xoá cấu hình thông báo
            DB::table('notification_preferences')->where('user_id', $userId)->delete();
            
            // Xoá các lượt gửi thông báo liên quan đến bảng notifications
            DB::table('notification_deliveries')->whereIn('notification_id', function ($query) use ($userId) {
                $query->select('id')->from('notifications')->where('user_id', $userId);
            })->delete();
            DB::table('notifications')->where('user_id', $userId)->delete();

            // Xoá quy tắc định kỳ và lịch sử thực thi
            DB::table('recurring_executions')->whereIn('recurring_rule_id', function ($query) use ($userId) {
                $query->select('id')->from('recurring_rules')->where('user_id', $userId);
            })->delete();
            DB::table('recurring_rules')->where('user_id', $userId)->delete();

            // Xoá tệp đính kèm giao dịch
            DB::table('transaction_attachments')->whereIn('transaction_id', function ($query) use ($userId) {
                $query->select('id')->from('transactions')->where('user_id', $userId);
            })->delete();
            // Xoá lịch sử thay đổi giao dịch
            DB::table('transaction_audits')->whereIn('transaction_id', function ($query) use ($userId) {
                $query->select('id')->from('transactions')->where('user_id', $userId);
            })->delete();

            // Xoá chuyển khoản giữa các ví
            DB::table('wallet_transfers')->whereIn('from_wallet_id', function ($query) use ($userId) {
                $query->select('id')->from('wallets')->where('user_id', $userId);
            })->orWhereIn('to_wallet_id', function ($query) use ($userId) {
                $query->select('id')->from('wallets')->where('user_id', $userId);
            })->delete();

            // Xoá số dư ví
            DB::table('wallet_balances')->whereIn('wallet_id', function ($query) use ($userId) {
                $query->select('id')->from('wallets')->where('user_id', $userId);
            })->delete();

            // Xoá giao dịch
            DB::table('transactions')->where('user_id', $userId)->delete();

            // Xoá hạn mức ngân sách (Budgets)
            DB::table('budget_usages')->whereIn('budget_id', function ($query) use ($userId) {
                $query->select('id')->from('budgets')->where('user_id', $userId);
            })->delete();
            DB::table('budget_alerts')->whereIn('budget_id', function ($query) use ($userId) {
                $query->select('id')->from('budgets')->where('user_id', $userId);
            })->delete();
            DB::table('budgets')->where('user_id', $userId)->delete();

            // Xoá ví
            DB::table('wallets')->where('user_id', $userId)->delete();

            // Xoá danh mục
            DB::table('categories')->where('user_id', $userId)->delete();

            // Xoá credential, preference, profile, session, oauth
            DB::table('user_credentials')->where('user_id', $userId)->delete();
            DB::table('user_preferences')->where('user_id', $userId)->delete();
            DB::table('user_profiles')->where('user_id', $userId)->delete();
            DB::table('user_sessions')->where('user_id', $userId)->delete();
            DB::table('oauth_accounts')->where('user_id', $userId)->delete();

            // Cuối cùng xoá bản ghi chính trong bảng users
            DB::table('users')->where('user_id', $userId)->delete();
        });
    }

    /**
     * Lấy danh sách các phiên đăng nhập đang hoạt động của người dùng
     */
    public function getActiveSessions(string $userId)
    {
        return DB::table('user_sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expired_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get(['id', 'device_type', 'device_name', 'ip_address', 'user_agent', 'created_at', 'expired_at']);
    }

    /**
     * Hủy bỏ một phiên đăng nhập cụ thể của người dùng
     */
    public function revokeSession(string $userId, string $sessionId): void
    {
        DB::table('user_sessions')
            ->where('user_id', $userId)
            ->where('id', $sessionId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now()
            ]);
    }

    private function createDefaultCashWallet(string $userId): void
    {
        $walletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id'            => $walletId,
            'user_id'       => $userId,
            'name'          => 'Tiền mặt',
            'type'          => 'cash',
            'currency_code' => 'VND',
            'icon'          => 'cash',
            'color'         => '#4C4DDC',
            'is_hidden'     => false,
            'is_default_receiving' => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id'         => $walletId,
            'available_balance' => 0.00,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}