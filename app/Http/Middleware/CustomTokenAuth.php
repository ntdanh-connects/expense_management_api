<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Lấy Token bảo mật từ Header 'Authorization' do Frontend gửi lên
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Truy cập bị chặn! Bạn chưa đăng nhập hoặc thiếu Token bảo mật.'
            ], 401); // 401 Unauthorized
        }

        $token = $matches[1];

        // 2. Đối chiếu access_token_hash trong DB
        $session = DB::table('user_sessions')
            ->where('access_token_hash', hash('sha256', $token)) 
            ->where('access_token_expired_at', '>', now())                  
            ->whereNull('revoked_at')                       
            ->first();

        if (!$session) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Phiên đăng nhập đã hết hạn hoặc không hợp lệ! Vui lòng đăng nhập lại.'
            ], 401);
        }

        $request->attributes->set('user_id', $session->user_id);
        $request->attributes->set('session_id', $session->id);

        return $next($request);
    }
}
