<?php

namespace App\Http\Controllers;

use App\Services\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    protected $budgetService;

    public function __construct(BudgetService $budgetService)
    {
        $this->budgetService = $budgetService;
    }

    /**
     * Lấy danh sách ngân sách của user kèm tiến độ
     * GET /api/budgets
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'month' => 'nullable|integer|between:1,12',
                'year' => 'nullable|integer|min:2000'
            ]);

            $month = $validated['month'] ?? (int) now()->format('m');
            $year = $validated['year'] ?? (int) now()->format('Y');

            $budgets = $this->budgetService->getAllUserBudgets($userId, $month, $year);

            return response()->json([
                'status' => 'success',
                'message' => 'Lấy danh sách ngân sách thành công!',
                'data' => $budgets
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Tạo mới hoặc cập nhật ngân sách
     * POST /api/budgets
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'category_id' => 'nullable|uuid',
                'limit_amount' => 'required|numeric|min:1000',
                'month' => 'required|integer|between:1,12',
                'year' => 'required|integer|min:2000'
            ]);

            $budget = $this->budgetService->createOrUpdateBudget($userId, $validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Thiết lập hạn mức ngân sách thành công!',
                'data' => $budget
            ], 201);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Xóa ngân sách
     * DELETE /api/budgets/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $this->budgetService->deleteBudget($id, $userId);

            return response()->json([
                'status' => 'success',
                'message' => 'Xóa ngân sách thành công!'
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Sao chép toàn bộ cấu hình ngân sách từ tháng cũ sang tháng mới
     * POST /api/budgets/copy
     */
    public function copy(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'from_month' => 'required|integer|between:1,12',
                'from_year' => 'required|integer|min:2000',
                'to_month' => 'required|integer|between:1,12',
                'to_year' => 'required|integer|min:2000',
            ]);

            $copied = $this->budgetService->copyBudgets($userId, $validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Sao chép ngân sách thành công!',
                'data' => $copied
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
