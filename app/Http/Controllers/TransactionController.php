<?php

namespace App\Http\Controllers;

use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * API 1: Lấy danh sách giao dịch có bộ lọc và phân trang
     * GET /api/transactions
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            // Thu thập các filter
            $filters = $request->only([
                'search',
                'start_date',
                'end_date',
                'category_id',
                'type',
                'min_amount',
                'max_amount',
                'wallet_id'
            ]);

            $sortBy = $request->query('sort_by', 'date');
            $sortOrder = $request->query('sort_order', 'desc');
            $perPage = (int) $request->query('per_page', 20);

            $transactions = $this->transactionService->getTransactions($userId, $filters, $sortBy, $sortOrder, $perPage);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.get_transactions_list_success'),
                'data'    => $transactions
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * API 2: Tạo giao dịch thủ công (POST /api/transactions)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'wallet_id'        => 'required|uuid',
                'category_id'      => 'nullable|uuid',
                'type'             => 'required|string|in:income,expense',
                'amount'           => 'required|numeric|min:0.01',
                'title'            => 'required|string|max:255',
                'notes'            => 'nullable|string|max:1000',
                'transaction_date' => 'nullable|date',
                'currency_code'    => 'nullable|string|max:10',
                'exchange_rate'    => 'nullable|numeric|min:0.000001',
                'timezone'         => 'nullable|string|timezone',
                'attachment'       => 'nullable|file|image|mimes:jpeg,png,jpg,gif|max:102400' // Tối đa 100MB
            ]);

            $transaction = $this->transactionService->createTransaction($userId, $validated, $request->file('attachment'));

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.create_transaction_success'),
                'data'    => $transaction
            ], 201);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API 3: Chi tiết giao dịch (GET /api/transactions/{id})
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $transaction = $this->transactionService->getTransactionById($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.get_transaction_detail_success'),
                'data'    => $transaction
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API 4: Chỉnh sửa giao dịch (POST /api/transactions/{id} để hỗ trợ gửi ảnh qua multipart/form-data)
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'wallet_id'        => 'sometimes|required|uuid',
                'category_id'      => 'nullable|uuid',
                'type'             => 'sometimes|required|string|in:income,expense',
                'amount'           => 'sometimes|required|numeric|min:0.01',
                'title'            => 'sometimes|required|string|max:255',
                'notes'            => 'nullable|string|max:1000',
                'transaction_date' => 'sometimes|required|date',
                'currency_code'    => 'nullable|string|max:10',
                'exchange_rate'    => 'nullable|numeric|min:0.000001',
                'timezone'         => 'nullable|string|timezone',
                'attachment'       => 'nullable|file|image|mimes:jpeg,png,jpg,gif|max:102400'
            ]);

            $transaction = $this->transactionService->updateTransaction($id, $userId, $validated, $request->file('attachment'));

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.update_transaction_success'),
                'data'    => $transaction
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API 5: Xóa giao dịch (DELETE /api/transactions/{id})
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $this->transactionService->deleteTransaction($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.delete_transaction_success')
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
