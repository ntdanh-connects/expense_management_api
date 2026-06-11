<?php

namespace App\Http\Controllers;

use App\Models\UserDeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceTokenController extends Controller
{
    /**
     * POST /api/user/device-token
     * Đăng ký hoặc cập nhật token thiết bị cho user
     */
    public function register(Request $request)
    {
        $userId = $request->attributes->get('user_id');

        $validator = Validator::make($request->all(), [
            'device_token' => 'required|string',
            'device_type' => 'required|string|in:android,ios,web,desktop'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dữ liệu không hợp lệ.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Đăng ký token mới, hoặc nếu đã tồn tại thì cập nhật user_id mới (khi đổi tài khoản trên thiết bị)
        $token = UserDeviceToken::updateOrCreate(
            ['device_token' => $request->input('device_token')],
            [
                'user_id' => $userId,
                'device_type' => $request->input('device_type')
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Đăng ký thiết bị nhận thông báo thành công.',
            'data' => $token
        ]);
    }

    /**
     * DELETE /api/user/device-token
     * Xóa token thiết bị khi user đăng xuất
     */
    public function unregister(Request $request)
    {
        $userId = $request->attributes->get('user_id');

        $validator = Validator::make($request->all(), [
            'device_token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dữ liệu không hợp lệ.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Xóa token khỏi database để không gửi thông báo nữa
        UserDeviceToken::where('device_token', $request->input('device_token'))
            ->where('user_id', $userId)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Hủy đăng ký thiết bị thành công.'
        ]);
    }
}
