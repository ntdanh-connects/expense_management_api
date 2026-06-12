<?php

namespace App\Http\Controllers;

use App\Services\RecurringTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringRuleController extends Controller
{
    protected $recurringService;

    public function __construct(RecurringTransactionService $recurringService)
    {
        $this->recurringService = $recurringService;
    }

    /**
     * API 1: Lấy danh sách luật định kỳ của user
     * GET /api/recurring-rules
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $rules = $this->recurringService->getAllRules($userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.get_recurring_rules_success'),
                'data'    => $rules
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * API 2: Tạo luật định kỳ mới (POST /api/recurring-rules)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'wallet_id'      => 'required|uuid',
                'category_id'    => 'nullable|uuid',
                'type'           => 'required|string|in:income,expense',
                'amount'         => 'required|numeric|min:0.01',
                'title'          => 'required|string|max:255',
                'frequency'      => 'required|string|in:daily,weekly,monthly,yearly',
                'interval_value' => 'nullable|integer|min:1',
                'next_run_at'    => 'nullable|date',
                'end_at'         => 'nullable|date|after_or_equal:next_run_at',
                'is_active'      => 'nullable|boolean'
            ]);

            $wallet = DB::table('wallets')->where('id', $validated['wallet_id'])->where('user_id', $userId)->first();
            if (!$wallet) {
                return response()->json(['status' => 'error', 'message' => 'Ví không tồn tại hoặc bạn không có quyền sử dụng ví này.'], 404);
            }

            if ($wallet->type === 'cash') {
                return response()->json(['status' => 'error', 'message' => 'Giao dịch định kỳ chỉ hỗ trợ ví Ngân hàng hoặc Ví điện tử.'], 400);
            }

            if ($wallet->currency_code !== 'VND') {
                return response()->json(['status' => 'error', 'message' => 'Giao dịch định kỳ chỉ hỗ trợ đơn vị tiền tệ VND.'], 400);
            }
 
            $rule = $this->recurringService->createRule($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.create_recurring_rule_success'),
                'data'    => $rule
            ], 201);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API 3: Cập nhật luật định kỳ (POST /api/recurring-rules/{id})
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'wallet_id'      => 'sometimes|required|uuid',
                'category_id'    => 'nullable|uuid',
                'type'           => 'sometimes|required|string|in:income,expense',
                'amount'         => 'sometimes|required|numeric|min:0.01',
                'title'          => 'sometimes|required|string|max:255',
                'frequency'      => 'sometimes|required|string|in:daily,weekly,monthly,yearly',
                'interval_value' => 'nullable|integer|min:1',
                'next_run_at'    => 'sometimes|required|date',
                'end_at'         => 'nullable|date|after_or_equal:next_run_at',
                'is_active'      => 'sometimes|required|boolean'
            ]);

            if (isset($validated['wallet_id'])) {
                $wallet = DB::table('wallets')->where('id', $validated['wallet_id'])->where('user_id', $userId)->first();
                if (!$wallet) {
                    return response()->json(['status' => 'error', 'message' => 'Ví không tồn tại hoặc bạn không có quyền sử dụng ví này.'], 404);
                }
                if ($wallet->type === 'cash') {
                    return response()->json(['status' => 'error', 'message' => 'Giao dịch định kỳ chỉ hỗ trợ ví Ngân hàng hoặc Ví điện tử.'], 400);
                }
                if ($wallet->currency_code !== 'VND') {
                    return response()->json(['status' => 'error', 'message' => 'Giao dịch định kỳ chỉ hỗ trợ đơn vị tiền tệ VND.'], 400);
                }
            }
 
            $rule = $this->recurringService->updateRule($id, $userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.update_recurring_rule_success'),
                'data'    => $rule
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API 4: Xóa luật định kỳ (DELETE /api/recurring-rules/{id})
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $this->recurringService->deleteRule($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.delete_recurring_rule_success')
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API 5: Bật/tắt kích hoạt luật định kỳ (POST /api/recurring-rules/{id}/toggle)
     */
    public function toggle(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $rule = $this->recurringService->toggleRule($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => $rule->is_active ? __('messages.activate_recurring_rule_success') : __('messages.pause_recurring_rule_success'),
                'data'    => $rule
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
