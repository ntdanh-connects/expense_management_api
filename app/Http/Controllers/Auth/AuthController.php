<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Exception;

class AuthController extends Controller{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function register(RegisterRequest $request):JsonResponse{
        try{
            $user = $this->userService->registerUser($request->validated());
            return response()->json([
                'message'       => 'Đăng kí tài khoản thành công',
                'data'          =>  $user->load('profile','preference')
            ],201);
        }catch(\Throwable $e){ 
            return response()->json([
                'message' => 'Đăng kí thất bại !',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function verify(Request $request,$id,$hash): JsonResponse{
        try{
            if(! $request->hasValidSignature()){
                return response()->json([
                    'message' => 'Đường dẫn hiện tại đã hết hạn hoặc sai cấu trúc'
                ], 403);
            }
            $user = \App\Models\User::findOrFail($id);

            if(! hash_equals((string) $hash, sha1($user->email))){
                return response()->json(['message' => 'Mã xác thực không trùng khớp!'], 403);
            }
            if ($user->email_verified_at) {
                return response()->json(['message' => 'Tài khoản này đã được kích hoạt trước đó.'], 200);
            }

            $user->update(['email_verified_at' => now()]);

            return response()->json(['message' => 'Kích hoạt tài khoản đồ án thành công!'], 200);

        }catch (Exception $e){
            return response()->json([
                'message' => 'Lỗi xác thực tài khoản !',
                'error' => $e->getMessage()
            ],500);
        }
    }

    public function login(Request $request){
        try{
            $creadential = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string'
            ]);

            $deviceData = [
                'device_type' => $request->header('X-Device-Type', 'web'),
                'device_name' => $request->header('User-Agent') ? explode(' ', $request->header('User-Agent'))[0] : 'Unknown',
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->header('User-Agent'),
            ];

            $result = $this->userService->loginUser($creadential,$deviceData);

            return response()->json([
                'message'       => 'Đăng nhập thành công!',
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => 'Bearer',
                'data'          => $result['user']->load('profile', 'preference')
            ], 200);

        }catch(\Throwable $e){
            return response()->json([
                'message' => 'Đăng nhập thất bại !',
                'error' => $e->getMessage()
            ],401);
        }
    }

    public function refreshToken(Request $request){
        try{
            $request->validate([
                'user_id' => 'required|uuid',
                'refresh_token' => 'required|string'
            ]);

            $result = $this->userService->refreshToken($request->user_id,$request->refresh_token);

            return response()->json([
                'status'        => 'success',
                'message'       => 'Gia hạn phiên làm việc mới thành công!',
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => 'Bearer',
                'data'          => $result['user']->load('profile', 'preference')
            ]);
        }catch (\Throwable $e){
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 401);
        }
    }

    public function getProfile(Request $request)
    {
        try {
           $userId = $request->input('user_id') 
                      ?? $request->attributes->get('user_id') 
                      ?? $request->header('X-User-Id');

            $user = $this->userService->getUserProfile($userId);

            return response()->json([
                'status'  => 'success',
                'message' => 'Lấy dữ liệu đồng bộ Profile thành công!',
                'data'    => $user
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function socialLogin(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'provider'     => 'required|string',
                'token'        => 'required|string',
                'redirect_uri' => 'nullable|string'
            ]);

            $deviceData = [
                'device_type' => $request->header('X-Device-Type', 'web'),
                'device_name' => $request->header('User-Agent') ? explode(' ', $request->header('User-Agent'))[0] : 'Unknown',
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->header('User-Agent'),
            ];

            $result = $this->userService->socialLogin(
                $validated['provider'], 
                $validated['token'], 
                $deviceData, 
                $validated['redirect_uri'] ?? null
            );

            if (isset($result['status']) && $result['status'] === 'requires_linking') {
                return response()->json([
                    'status'     => 'requires_linking',
                    'message'    => $result['message'],
                    'link_token' => $result['link_token'],
                    'email'      => $result['email']
                ], 200);
            }

            return response()->json([
                'status'        => 'success',
                'message'       => 'Đăng nhập mạng xã hội thành công!',
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => 'Bearer',
                'data'          => $result['user']->load('profile', 'preference')
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function linkSocial(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'link_token' => 'required|string',
                'password'   => 'required|string'
            ]);

            $deviceData = [
                'device_type' => $request->header('X-Device-Type', 'web'),
                'device_name' => $request->header('User-Agent') ? explode(' ', $request->header('User-Agent'))[0] : 'Unknown',
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->header('User-Agent'),
            ];

            $result = $this->userService->linkSocialAccount($validated['link_token'], $validated['password'], $deviceData);

            return response()->json([
                'status'        => 'success',
                'message'       => 'Liên kết và xác thực tài khoản thành công!',
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => 'Bearer',
                'data'          => $result['user']->load('profile', 'preference')
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    function logout(Request $request):JsonResponse{
        try{
            $token = $request->bearerToken();
            
            if($token){
                $this->userService->logoutCurrentDevice($token);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Đăng xuất thiết bị hiện tại thành công!'
            ],200);
        }catch(\Throwable $e){
            return response()->json([
                'status' => 'error',
                'message' => 'Đăng xuất thất bại!',
                'error' => $e->getMessage()
            ],500);
        }
    }

    function logoutAllDevices(Request $request):JsonResponse{
        try{
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if($userId){
                $this->userService->logoutAllDevices($userId);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Đăng xuất và thu hồi phiên đăng nhập trên toàn bộ thiết bị thành công!'
            ],200);
        }catch(\Throwable $e){
            return response()->json([
                'status'=>'error',
                'message'=>'Đăng xuất và thu hồi phiên đăng nhập tất cả thiết bị thất bại',
                'error'=>$e->getMessage()
            ],500);
        }
    }

    /**
     * API Cập nhật ảnh đại diện lên AWS S3
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:20480' // Tối đa 20MB
        ]);

        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                throw new \Exception("Không thể xác định danh tính người dùng!");
            }

            $file = $request->file('avatar');
            $profile = $this->userService->updateAvatar($userId, $file);

            return response()->json([
                'status'  => 'success',
                'message' => 'Cập nhật ảnh đại diện lên đám mây S3 thành công!',
                'data'    => $profile
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Lỗi API cập nhật ảnh đại diện:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Cập nhật ảnh đại diện thất bại!',
                'error'   => $e->getMessage()
            ], 400);
        }
    }

    /**
     * API Cập nhật thông tin cá nhân & Cài đặt (Họ tên, Đơn vị tiền tệ, Múi giờ, v.v.)
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'full_name' => 'nullable|string|max:255',
            'currency'  => 'nullable|string|max:10',
            'timezone'  => 'nullable|string|max:100',
            'theme'     => 'nullable|string|in:light,dark',
            'language'  => 'nullable|string|in:vi,en',
        ]);

        $validated = array_filter($request->only([
            'full_name', 'currency', 'timezone', 'theme', 'language'
        ]), fn($value) => !is_null($value));

        if (empty($validated)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Không có thông tin nào được gửi để cập nhật!'
            ], 400);
        }

        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                throw new \Exception("Không thể xác định danh tính người dùng!");
            }

            $profile = $this->userService->updateProfile($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Cập nhật thông tin cá nhân thành công!',
                'data'    => $profile
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Lỗi API cập nhật profile/preferences:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Cập nhật thông tin cá nhân thất bại!',
                'error'   => $e->getMessage()
            ], 400);
        }
    }

    /**
     * API Gửi link đặt lại mật khẩu qua email
     */
    public function sendResetLinkEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Không tìm thấy tài khoản tương ứng với email này!'
                ], 404);
            }

            // Tạo token ngẫu nhiên cực bảo mật
            $token = Str::random(64);

            // Lưu hoặc cập nhật vào bảng password_reset_tokens
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now()
                ]
            );

            // Gửi email qua Resend sử dụng lớp Notification
            $user->notify(new ResetPasswordNotification($user, $token));

            return response()->json([
                'status' => 'success',
                'message' => 'Đường dẫn đặt lại mật khẩu đã được gửi đến email của sếp!'
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Lỗi gửi email đặt lại mật khẩu:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Gửi email đặt lại mật khẩu thất bại!',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Web hiển thị Form đặt lại mật khẩu
     */
    public function showResetForm(Request $request, $token)
    {
        $email = $request->query('email');

        if (!$email) {
            return response('Đường dẫn không hợp lệ! Thiếu thông tin email xác minh.', 400);
        }

        // Kiểm tra xem token và email có tồn tại khớp nhau không
        $resetRecord = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$resetRecord || !Hash::check($token, $resetRecord->token)) {
            return response('Đường dẫn đặt lại mật khẩu không chính xác hoặc đã được sử dụng!', 400);
        }

        // Kiểm tra xem token có bị hết hạn không (60 phút)
        if (now()->subMinutes(60)->gt($resetRecord->created_at)) {
            // Xoá luôn token hết hạn cho sạch DB
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response('Đường dẫn đặt lại mật khẩu đã hết hạn sau 60 phút!', 400);
        }

        return view('auth.reset-password', [
            'token' => $token,
            'email' => $email
        ]);
    }

    /**
     * Xử lý POST đổi mật khẩu mới
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed'
        ]);

        try {
            $resetRecord = DB::table('password_reset_tokens')->where('email', $request->email)->first();

            if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
                return back()->withErrors(['email' => 'Đường dẫn đặt lại mật khẩu không hợp lệ hoặc đã hết hạn!']);
            }

            // Kiểm tra hết hạn 60 phút
            if (now()->subMinutes(60)->gt($resetRecord->created_at)) {
                DB::table('password_reset_tokens')->where('email', $request->email)->delete();
                return back()->withErrors(['email' => 'Đường dẫn đặt lại mật khẩu đã hết hạn!']);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return back()->withErrors(['email' => 'Không tìm thấy tài khoản tương ứng trong hệ thống!']);
            }

            // Cập nhật hoặc Tạo mới bản ghi mật khẩu (Áp dụng cơ chế Đăng Nhập Kép cho tài khoản Google/GitHub)
            $user->credential()->updateOrCreate(
                ['user_id' => $user->user_id],
                [
                    'password_hash' => Hash::make($request->password),
                    'password_changed_at' => now()
                ]
            );

            // Thu hồi token cũ sau khi đổi thành công để bảo mật
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            // Hiển thị giao diện thành công tuyệt đẹp!
            return view('auth.reset-success');

        } catch (\Throwable $e) {
            Log::error('Lỗi khi thiết lập mật khẩu mới:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withErrors(['error' => 'Thiết lập mật khẩu mới thất bại! Lỗi: ' . $e->getMessage()]);
        }
    }

    /**
     * API Đổi mật khẩu khi đã đăng nhập (Thu hồi toàn bộ thiết bị)
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed'
        ]);

        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                throw new \Exception("Không thể xác định danh tính người dùng!");
            }

            $user = User::findOrFail($userId);

            // Kiểm tra mật khẩu hiện tại
            if (!$user->credential || !Hash::check($request->current_password, $user->credential->password_hash)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Mật khẩu hiện tại không chính xác!'
                ], 400);
            }

            // Cập nhật mật khẩu mới (dùng updateOrCreate phòng trường hợp user đăng nhập qua MXH chưa có bản ghi credential)
            $user->credential()->updateOrCreate(
                ['user_id' => $user->user_id],
                [
                    'password_hash' => Hash::make($request->password),
                    'password_changed_at' => now()
                ]
            );
            
            $this->userService->logoutAllDevices($userId);

            return response()->json([
                'status'  => 'success',
                'message' => 'Đổi mật khẩu thành công! Tất cả phiên đăng nhập trên các thiết bị đã được thu hồi.'
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Lỗi khi đổi mật khẩu user ' . ($userId ?? 'unknown') . ':', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Đổi mật khẩu thất bại!',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * API Xoá vĩnh viễn tài khoản và toàn bộ dữ liệu liên quan
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                throw new \Exception("Không thể xác định danh tính người dùng!");
            }

            $this->userService->deleteUserAccount($userId);

            return response()->json([
                'status'  => 'success',
                'message' => 'Tài khoản của bạn và toàn bộ dữ liệu liên quan đã được xoá vĩnh viễn khỏi hệ thống.'
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Lỗi API xoá tài khoản:', [
                'user_id' => $userId ?? 'unknown',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Xoá tài khoản thất bại!',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}