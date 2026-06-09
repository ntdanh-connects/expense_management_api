<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Controller xác thực kênh broadcast (Pusher private channel).
 *
 * Vì project dùng custom token auth (CustomTokenAuth middleware) thay vì
 * Sanctum/Passport, ta KHÔNG thể dùng Broadcast::routes() mặc định
 * (nó yêu cầu Auth::user()). Controller này tự xử lý:
 *   1. Lấy user_id từ request attributes (đã set bởi CustomTokenAuth)
 *   2. Load model User
 *   3. Set user vào Auth guard tạm thời
 *   4. Gọi Broadcast::auth() để Pusher SDK ký xác thực kênh
 */
class BroadcastController extends Controller
{
    /**
     * Xác thực subscription cho private channel.
     *
     * Frontend (Laravel Echo / Pusher JS) sẽ POST đến endpoint này với:
     *   - socket_id: ID socket của client
     *   - channel_name: tên kênh (vd: private-user.xxx)
     *
     * Response trả về auth signature để client dùng subscribe vào kênh.
     */
    public function authenticate(Request $request)
    {
        // Lấy user_id đã được CustomTokenAuth middleware set
        $userId = $request->attributes->get('user_id');

        if (!$userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Không xác thực được người dùng.'
            ], 401);
        }

        // Load model User từ DB
        $user = User::where('user_id', $userId)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Người dùng không tồn tại.'
            ], 404);
        }

        // Set user vào Auth guard tạm thời để Broadcast::auth() hoạt động
        // Broadcast::auth() bên trong gọi $request->user() → cần Auth::setUser()
        auth()->setUser($user);

        // Gọi Broadcast::auth() — nó sẽ kiểm tra channel authorization
        // dựa trên callback đã định nghĩa trong routes/channels.php
        return Broadcast::auth($request);
    }
}
