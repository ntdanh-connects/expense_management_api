<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Get a paginated list of activity logs for the current user.
     *
     * GET /api/activity-logs
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.user_id_required')
                ], 400);
            }

            $query = ActivityLog::where('user_id', $userId)
                ->orderBy('created_at', 'desc');

            // Filter by action group (e.g., group=auth -> matches action 'auth.*')
            if ($request->has('group') && !empty($request->query('group'))) {
                $group = $request->query('group');
                $query->where('action', 'like', $group . '.%');
            }

            // Filter by keyword search in description
            if ($request->has('search') && !empty($request->query('search'))) {
                $search = $request->query('search');
                $query->where('description', 'like', '%' . $search . '%');
            }

            $perPage = $request->query('per_page', 15);
            $logs = $query->paginate($perPage);

            return response()->json([
                'status'  => 'success',
                'message' => 'Lấy danh sách nhật ký hoạt động thành công.',
                'data'    => $logs
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Không thể lấy nhật ký hoạt động.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
