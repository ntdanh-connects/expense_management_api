<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Lấy danh sách thông báo của người dùng (phân trang)
     */
    public function index(Request $request)
    {
        $userId = $request->attributes->get('user_id');

        $notifications = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // Map để định dạng lại phần metadata từ JSON string/object
        $items = collect($notifications->items())->map(function ($notification) {
            $notification->metadata = is_string($notification->metadata) 
                ? json_decode($notification->metadata, true) 
                : $notification->metadata;
            return $notification;
        });

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'pagination' => [
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage()
            ]
        ]);
    }

    /**
     * POST /api/notifications/{id}/read
     * Đánh dấu thông báo đã đọc
     */
    public function read(Request $request, string $id)
    {
        $userId = $request->attributes->get('user_id');

        $updated = DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->update([
                'read_at' => now(),
                'updated_at' => now()
            ]);

        if (!$updated) {
            return response()->json([
                'status' => 'error',
                'message' => 'Không tìm thấy thông báo hoặc bạn không có quyền.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Đã đánh dấu thông báo là đã đọc.'
        ]);
    }

    /**
     * POST /api/notifications/read-all
     * Đánh dấu đọc tất cả thông báo chưa đọc
     */
    public function readAll(Request $request)
    {
        $userId = $request->attributes->get('user_id');

        DB::table('notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now()
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Đã đánh dấu đọc tất cả thông báo.'
        ]);
    }

    /**
     * DELETE /api/notifications/{id}
     * Xóa thông báo khỏi danh sách
     */
    public function destroy(Request $request, string $id)
    {
        $userId = $request->attributes->get('user_id');

        $deleted = DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'status' => 'error',
                'message' => 'Không tìm thấy thông báo hoặc bạn không có quyền.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Đã xóa thông báo.'
        ]);
    }
}
