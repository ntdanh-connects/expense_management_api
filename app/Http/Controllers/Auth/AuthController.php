<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'provider' => 'required|string',
                'token'    => 'required|string'
            ]);

            $deviceData = [
                'device_type' => $request->header('X-Device-Type', 'web'),
                'device_name' => $request->header('User-Agent') ? explode(' ', $request->header('User-Agent'))[0] : 'Unknown',
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->header('User-Agent'),
            ];

            $result = $this->userService->socialLogin($validated['provider'], $validated['token'], $deviceData);

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
}