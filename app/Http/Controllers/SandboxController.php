<?php

namespace App\Http\Controllers;

use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;

class SandboxController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Giả lập nhận tiền từ một tài khoản ngân hàng bên ngoài vào ví (Wallet) của người dùng.
     */
    public function simulateTransfer(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => 'User ID is required.'], 400);
            }

            $validated = $request->validate([
                'wallet_id'   => 'required|uuid',
                'amount'      => 'required|numeric|min:0.01',
                'sender_name' => 'nullable|string|max:255',
                'notes'       => 'nullable|string|max:1000',
            ]);

            $walletId = $validated['wallet_id'];
            $amount = (float) $validated['amount'];

            // 1. Kiểm tra ví có thuộc user không
            $wallet = DB::table('wallets')
                ->where('id', $walletId)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->first();

            if (!$wallet) {
                return response()->json(['status' => 'error', 'message' => 'Không tìm thấy ví hoặc bạn không có quyền truy cập.'], 404);
            }

            // Chặn chuyển tiền sandbox vào ví không phải bank hoặc ewallet
            if (!in_array($wallet->type, ['bank', 'ewallet'])) {
                return response()->json(['status' => 'error', 'message' => 'Chỉ hỗ trợ giả lập nhận tiền từ Sandbox vào ví ngân hàng hoặc ví điện tử.'], 400);
            }

            // Chặn chuyển tiền sandbox vào ví ngoại tệ (chỉ cho phép VND)
            if ($wallet->currency_code !== 'VND') {
                return response()->json(['status' => 'error', 'message' => 'Chỉ hỗ trợ giả lập nhận tiền từ Sandbox vào ví VND.'], 400);
            }

            // 2. Chuẩn bị dữ liệu để gọi TransactionService
            $senderName = $validated['sender_name'] ?? 'VietinBank Sandbox User';
            $notes = $validated['notes'] ?? 'Chuyển tiền từ Sandbox';

            // Tạo giao dịch thu nhập (income)
            $transactionData = [
                'wallet_id'        => $walletId,
                'category_id'      => null,
                'type'             => 'income',
                'amount'           => $amount,
                'title'            => "Nhận tiền từ {$senderName}",
                'notes'            => $notes,
                'source_type'      => 'import',
                'transaction_date' => Carbon::now()->toDateTimeString(),
            ];

            // 3. Gọi TransactionService tạo giao dịch
            $transaction = $this->transactionService->createTransaction($userId, $transactionData);

            // 4. Lấy số dư khả dụng mới của ví
            $newBalance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_balance') ?? 0.00;

            return response()->json([
                'status' => 'success',
                'message' => 'Giả lập nhận tiền từ Sandbox thành công.',
                'data' => [
                    'transaction' => $transaction,
                    'available_balance' => (float)$newBalance,
                ]
            ], 201);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
