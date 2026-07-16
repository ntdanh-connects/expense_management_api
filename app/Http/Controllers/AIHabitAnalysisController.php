<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AIHabitAnalysisController extends Controller
{
    /**
     * Lấy danh sách phân tích thói quen của user
     * GET /api/ai/habit-analyses
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $type = $request->query('type'); // daily, monthly, yearly
            $query = DB::table('ai_habit_analyses')
                ->where('user_id', $userId);

            if ($type) {
                $query->where('type', $type);
            }

            $analyses = $query->orderByDesc('analysis_date')
                ->orderByDesc('created_at')
                ->paginate(15);

            // Xây dựng analysis_data cho FE: FE parse `analysis_data['summary']`
            // còn BE lưu từng trường riêng (ai_insight, actual_amount, ...)
            $transformed = $analyses->getCollection()->map(function ($item) {
                $statusLabel = match ($item->status ?? 'normal') {
                    'overspending' => 'Chi tiêu cao hơn bình thường',
                    'saving'       => 'Tiết kiệm tốt hơn bình thường',
                    default        => 'Chi tiêu bình thường',
                };

                $item->analysis_data = [
                    'summary'          => $item->ai_insight ?? '',
                    'Trạng thái'       => $statusLabel,
                    'Chi tiêu thực tế' => number_format((float)($item->actual_amount ?? 0), 0, ',', '.') . ' ₫',
                    'Chi tiêu cơ sở'   => number_format((float)($item->baseline_amount ?? 0), 0, ',', '.') . ' ₫',
                    'Chênh lệch'       => (($item->diff_amount ?? 0) >= 0 ? '+' : '')
                                          . number_format((float)($item->diff_amount ?? 0), 0, ',', '.') . ' ₫',
                    'Thay đổi (%)'     => (($item->percent_change ?? 0) >= 0 ? '+' : '')
                                          . number_format((float)($item->percent_change ?? 0), 2) . '%',
                    'Chu kỳ'           => $item->period_range ?? '',
                ];

                return $item;
            });

            $analyses->setCollection($transformed);

            return response()->json([
                'status' => 'success',
                'data'   => $analyses
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


    /**
     * Đánh dấu phân tích là đã đọc
     * POST /api/ai/habit-analyses/{id}/read
     */
    public function markAsRead(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $updated = DB::table('ai_habit_analyses')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->update([
                    'is_read' => true,
                    'updated_at' => now()
                ]);

            if (!$updated) {
                return response()->json(['status' => 'error', 'message' => 'Không tìm thấy bản ghi phân tích hoặc không được quyền.'], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Đã đánh dấu là đã đọc.'
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
