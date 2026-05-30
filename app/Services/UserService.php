<?php

namespace App\Services;

use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Notifications\Auth\VerifyEmailNotification;
use Exception;
use Illuminate\Support\Facades\Http;

class UserService
{
    protected $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function registerUser(array $data)
    {
        return DB::transaction(function() use ($data){
            $uuid = (string) Str::uuid();

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
            
            $user->notify(new VerifyEmailNotification($user));

            return $user;
        });
    }

    public function loginUser(array $data, array $deviceData){
        $user = $this->userRepository->findByEmail($data['email']);

        if(!$user || !Hash::check($data['password'], $user->credential->password_hash)){
            throw new \Exception("Email hoặc mật khẩu không chính xác !");
        }

        if($user->status === 'suspended'){
            throw new \Exception('Tài khoản của bạn hiện tạm dừng');
        }

        if(is_null($user->email_verified_at)){
            throw new \Exception('Hiện tại email chưa được kích hoạt !, vui lòng truy cập mail để xác thực tài khoản');
        }

        $accessToken = Str::random(60);
        $refreshToken = Str::random(60);

        $existingSession = $user->sessions()->where(
            'user_agent', $deviceData['user_agent']
        )->first();

        if($existingSession){
            $existingSession->update([
                'refresh_token_hash' => hash('sha256', $refreshToken),
                'access_token_hash'  => hash('sha256', $accessToken),
                'ip_address'=> $deviceData['ip_address'],
                'expired_at' => now()->addDays(30)
            ]);
        }else{
             $user->sessions()->create([
            'id'                 => (string) Str::uuid(),
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'access_token_hash'  => hash('sha256', $accessToken),
            'device_type'        => $deviceData['device_type'],
            'device_name'        => $deviceData['device_name'],
            'ip_address'         => $deviceData['ip_address'],
            'user_agent'         => $deviceData['user_agent'],
            'expired_at'         => now()->addDays(30),
            'created_at'         => now()
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
            throw new \Exception("Phiên làm việc đã hết hạn hoặc mã xác thực không hợp lệ, vui lòng đăng nhập lại!");
        }

        $newAccessToken = Str::random(60);
        $newRefreshToken = Str::random(60);

        DB::table('user_sessions')->where('id',$session->id)->update([
            'refresh_token_hash' => hash('sha256',$newRefreshToken),
            'access_token_hash'  => hash('sha256',$newAccessToken),
            'expired_at'=> now()->addDays(30)
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
            throw new \Exception("Không tìm thấy thông tin người dùng trong hệ thống!");
        }

        if ($user->status === 'suspended') {
            throw new \Exception("Tài khoản của bạn đã bị khóa, vui lòng liên hệ ban quản trị!");
        }

        return $user;
    }

    public function socialLogin(string $provider, string $token, array $deviceData)
    {
        $provider = strtolower($provider);
        if ($provider !== 'google' && $provider !== 'github') {
            throw new \Exception("Nhà cung cấp xác thực không được hỗ trợ!");
        }

        $socialUser = $this->verifySocialToken($provider, $token);
        $providerId = $socialUser['id'];
        $email = $socialUser['email'];
        $fullName = $socialUser['name'] ?? 'Social User';
        $avatarUrl = $socialUser['avatar_url'] ?? null;

        if (!$email) {
            throw new \Exception("Không thể lấy email từ tài khoản $provider!");
        }

        // 1. Kiểm tra xem đã có user nào liên kết với social ID này chưa
        $columnName = $provider . '_id'; // google_id or github_id
        $user = \App\Models\User::where($columnName, $providerId)->first();

        if ($user) {
            // Đã liên kết trước đó -> Đăng nhập luôn!
            if ($user->status === 'suspended') {
                throw new \Exception("Tài khoản của bạn đã bị tạm dừng!");
            }
            return $this->generateUserSession($user, $deviceData);
        }

        // 2. Nếu chưa liên kết social ID, kiểm tra trùng email
        $userWithEmail = $this->userRepository->findByEmail($email);

        if ($userWithEmail) {
            // Đã tồn tại tài khoản có email này (đăng ký thủ công hoặc MXH khác)
            // Kích hoạt Safe Account Linking!
            if ($userWithEmail->status === 'suspended') {
                throw new \Exception("Tài khoản của bạn đã bị khóa!");
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
            $uuid = (string) Str::uuid();

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

            return $this->generateUserSession($user, $deviceData);
        });
    }

    public function linkSocialAccount(string $linkToken, string $password, array $deviceData)
    {
        try {
            $payload = \Illuminate\Support\Facades\Crypt::decrypt($linkToken);
        } catch (\Exception $e) {
            throw new \Exception("Token liên kết tài khoản không hợp lệ hoặc đã hết hạn!");
        }

        // Token chỉ có hiệu lực trong 10 phút
        if (now()->timestamp - $payload['timestamp'] > 600) {
            throw new \Exception("Phiên liên kết tài khoản đã hết hạn! Vui lòng thử lại.");
        }

        $email = $payload['email'];
        $provider = $payload['provider'];
        $providerId = $payload['provider_id'];
        $avatarUrl = $payload['avatar_url'];

        $user = $this->userRepository->findByEmail($email);
        if (!$user) {
            throw new \Exception("Không tìm thấy tài khoản người dùng tương ứng!");
        }

        if (!$user->credential || !Hash::check($password, $user->credential->password_hash)) {
            throw new \Exception("Mật khẩu xác nhận không chính xác!");
        }

        // Cập nhật liên kết social ID trực tiếp trong bảng users
        $columnName = $provider . '_id';
        
        // Đảm bảo social ID này chưa bị liên kết bởi tài khoản khác (tránh xung đột)
        $exists = \App\Models\User::where($columnName, $providerId)->where('user_id', '!=', $user->user_id)->exists();
        if ($exists) {
            throw new \Exception("Tài khoản $provider này đã được liên kết với một tài khoản khác trong hệ thống!");
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

    private function verifySocialToken(string $provider, string $token): array
    {
        if ($provider === 'google') {
            // 1. Thử xác thực dưới dạng ID Token (Thường dùng trên Mobile/Flutter)
            $response = Http::get("https://oauth2.googleapis.com/tokeninfo?id_token=" . $token);
            
            // 2. Nếu thất bại, thử xác thực dưới dạng Access Token
            if ($response->failed()) {
                $response = Http::withToken($token)->get("https://www.googleapis.com/oauth2/v3/userinfo");
            }

            if ($response->failed()) {
                throw new \Exception("Token Google không hợp lệ hoặc đã hết hạn!");
            }

            $emailVerified = filter_var($response->json('email_verified'), FILTER_VALIDATE_BOOLEAN);
            if (!$emailVerified) {
                throw new \Exception("Tài khoản Google này chưa được xác thực email!");
            }

            return [
                'id' => $response->json('sub'),
                'email' => $response->json('email'),
                'name' => $response->json('name'),
                'avatar_url' => $response->json('picture'),
            ];
        }

        if ($provider === 'github') {
            // GitHub sử dụng Access Token để lấy user info
            $response = Http::withToken($token)->get("https://api.github.com/user");
            
            if ($response->failed()) {
                throw new \Exception("Token GitHub không hợp lệ hoặc đã hết hạn!");
            }

            $email = $response->json('email');
            
            // Trường hợp email bị ẩn (private) trên GitHub
            if (!$email) {
                $emailsResponse = Http::withToken($token)->get("https://api.github.com/user/emails");
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

        throw new \Exception("Nhà cung cấp xác thực không được hỗ trợ!");
    }

    private function generateUserSession($user, array $deviceData): array
    {
        $accessToken = Str::random(60);
        $refreshToken = Str::random(60);

        $existingSession = $user->sessions()->where(
            'user_agent', $deviceData['user_agent']
        )->first();

        if ($existingSession) {
            $existingSession->update([
                'refresh_token_hash' => hash('sha256', $refreshToken),
                'access_token_hash'  => hash('sha256', $accessToken),
                'ip_address'         => $deviceData['ip_address'],
                'expired_at'         => now()->addDays(30)
            ]);
        } else {
            $user->sessions()->create([
                'id'                 => (string) Str::uuid(),
                'refresh_token_hash' => hash('sha256', $refreshToken),
                'access_token_hash'  => hash('sha256', $accessToken),
                'device_type'        => $deviceData['device_type'],
                'device_name'        => $deviceData['device_name'],
                'ip_address'         => $deviceData['ip_address'],
                'user_agent'         => $deviceData['user_agent'],
                'expired_at'         => now()->addDays(30),
                'created_at'         => now()
            ]);
        }

        return [
            'user'          => $user,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken
        ];
    }
}