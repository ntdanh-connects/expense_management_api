<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
     * API Cập nhật thông tin cá nhân (Họ tên)
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255'
        ]);

        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                throw new \Exception("Không thể xác định danh tính người dùng!");
            }

            $profile = $this->userService->updateProfile($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Cập nhật họ tên thành công!',
                'data'    => $profile
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Lỗi API cập nhật profile:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Cập nhật họ tên thất bại!',
                'error'   => $e->getMessage()
            ], 400);
        }
    }
}