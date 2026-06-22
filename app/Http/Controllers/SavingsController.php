<?php

namespace App\Http\Controllers;

use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SavingsController extends Controller
{
    /**
     * Lấy danh sách mục tiêu tiết kiệm
     * GET /api/savings
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $goals = SavingsGoal::where('user_id', $userId)
                ->with('sourceWallet:id,name')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status'  => 'success',
                'message' => 'Lấy danh sách mục tiêu tiết kiệm thành công.',
                'data'    => $goals
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Tạo mới một mục tiêu tiết kiệm
     * POST /api/savings
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'name'                 => 'required|string|max:255',
                'target_amount'        => 'required|numeric|min:1000|max:500000000', // Giới hạn 500 triệu
                'target_date'          => 'nullable|date|after:today',
                'auto_save_frequency'  => 'nullable|string|in:daily,weekly,monthly',
                'auto_save_amount'     => 'nullable|numeric|min:1000|max:500000000',
                'source_wallet_id'     => 'nullable|uuid'
            ]);

            if (!empty($validated['source_wallet_id'])) {
                $wallet = Wallet::where('id', $validated['source_wallet_id'])->where('user_id', $userId)->first();
                if (!$wallet) {
                    return response()->json(['status' => 'error', 'message' => 'Ví nguồn không hợp lệ hoặc không thuộc quyền sở hữu.'], 400);
                }
            }

            $validated['user_id'] = $userId;
            $validated['current_amount'] = 0.00;
            $validated['status'] = 'active';

            $goal = SavingsGoal::create($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Tạo mục tiêu tiết kiệm thành công.',
                'data'    => $goal
            ], 201);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Lấy chi tiết mục tiêu tiết kiệm bao gồm cả lịch sử giao dịch nạp rút
     * GET /api/savings/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $goal = SavingsGoal::where('id', $id)
                ->where('user_id', $userId)
                ->with(['sourceWallet:id,name', 'transactions' => function ($query) {
                    $query->orderBy('transaction_date', 'desc');
                }])
                ->first();

            if (!$goal) {
                return response()->json(['status' => 'error', 'message' => 'Mục tiêu tiết kiệm không tồn tại hoặc bạn không có quyền.'], 404);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Lấy chi tiết mục tiêu tiết kiệm thành công.',
                'data'    => $goal
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Nạp tiền vào mục tiêu tiết kiệm
     * POST /api/savings/{id}/deposit
     */
    public function deposit(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'amount'           => 'required|numeric|min:1000|max:500000000',
                'source_wallet_id' => 'required|uuid',
                'notes'            => 'nullable|string|max:255'
            ]);

            $amount = floatval($validated['amount']);
            $sourceWalletId = $validated['source_wallet_id'];
            $notes = $validated['notes'] ?? 'Tích lũy heo đất';

            $result = DB::transaction(function () use ($userId, $id, $amount, $sourceWalletId, $notes) {
                // Lock and retrieve Goal
                $goal = SavingsGoal::where('id', $id)->where('user_id', $userId)->lockForUpdate()->first();
                if (!$goal) {
                    throw new \Exception('Mục tiêu tiết kiệm không tồn tại hoặc bạn không có quyền thao tác.');
                }

                // Lock and retrieve Source Wallet Balance
                $wallet = Wallet::where('id', $sourceWalletId)->where('user_id', $userId)->first();
                if (!$wallet) {
                    throw new \Exception('Ví nguồn không tồn tại hoặc bạn không có quyền sở hữu.');
                }

                $walletBalance = DB::table('wallet_balances')->where('wallet_id', $sourceWalletId)->lockForUpdate()->first();
                if (!$walletBalance) {
                    throw new \Exception('Không tìm thấy dữ liệu số dư của ví nguồn.');
                }

                if (bccomp($walletBalance->available_balance, $amount, 2) === -1) {
                    throw new \Exception('Số dư ví nguồn không đủ để trích tích lũy.');
                }

                // Deduct source wallet balance
                $newWalletBalance = bcsub($walletBalance->available_balance, $amount, 2);
                DB::table('wallet_balances')->where('wallet_id', $sourceWalletId)->update([
                    'available_balance' => $newWalletBalance,
                    'updated_at'        => now()
                ]);

                // Add to goal balance
                $newGoalAmount = bcadd($goal->current_amount, $amount, 2);
                $goal->update([
                    'current_amount' => $newGoalAmount
                ]);

                // Create transaction history on the main wallet
                $tx = Transaction::create([
                    'id'                      => (string) Str::uuid7(),
                    'user_id'                 => $userId,
                    'wallet_id'               => $sourceWalletId,
                    'type'                    => 'expense',
                    'status'                  => 'completed',
                    'amount'                  => $amount,
                    'amount_in_user_currency' => $amount,
                    'currency_code'           => 'VND',
                    'exchange_rate'           => 1.00,
                    'title'                   => 'Tích lũy heo đất: ' . $goal->name,
                    'notes'                   => $notes,
                    'transaction_date'        => now(),
                    'source_type'             => 'transfer',
                    'source_id'               => $goal->id
                ]);

                // Create savings transaction log
                $savingsTx = SavingsTransaction::create([
                    'id'               => (string) Str::uuid7(),
                    'savings_goal_id'  => $goal->id,
                    'type'             => 'deposit',
                    'amount'           => $amount,
                    'source_wallet_id' => $sourceWalletId,
                    'transaction_date' => now(),
                    'notes'            => $notes
                ]);

                return ['goal' => $goal, 'savings_tx' => $savingsTx];
            });

            $result['goal']->load(['sourceWallet:id,name', 'transactions' => function ($query) {
                $query->orderBy('transaction_date', 'desc');
            }]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Nạp tiền vào mục tiêu tiết kiệm thành công.',
                'data'    => $result['goal']
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Rút tiền từ mục tiêu tiết kiệm về ví nguồn
     * POST /api/savings/{id}/withdraw
     */
    public function withdraw(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'amount'           => 'required|numeric|min:1000|max:500000000',
                'source_wallet_id' => 'required|uuid',
                'notes'            => 'nullable|string|max:255'
            ]);

            $amount = floatval($validated['amount']);
            $sourceWalletId = $validated['source_wallet_id'];
            $notes = $validated['notes'] ?? 'Rút tiền heo đất';

            $result = DB::transaction(function () use ($userId, $id, $amount, $sourceWalletId, $notes) {
                // Lock and retrieve Goal
                $goal = SavingsGoal::where('id', $id)->where('user_id', $userId)->lockForUpdate()->first();
                if (!$goal) {
                    throw new \Exception('Mục tiêu tiết kiệm không tồn tại hoặc bạn không có quyền thao tác.');
                }

                if (bccomp($goal->current_amount, $amount, 2) === -1) {
                    throw new \Exception('Số dư tích lũy hiện tại không đủ để rút số tiền yêu cầu.');
                }

                // Lock and retrieve Source Wallet
                $wallet = Wallet::where('id', $sourceWalletId)->where('user_id', $userId)->first();
                if (!$wallet) {
                    throw new \Exception('Ví nhận không tồn tại hoặc bạn không có quyền sở hữu.');
                }

                $walletBalance = DB::table('wallet_balances')->where('wallet_id', $sourceWalletId)->lockForUpdate()->first();
                if (!$walletBalance) {
                    throw new \Exception('Không tìm thấy dữ liệu số dư của ví nhận.');
                }

                // Add back to wallet balance
                $newWalletBalance = bcadd($walletBalance->available_balance, $amount, 2);
                DB::table('wallet_balances')->where('wallet_id', $sourceWalletId)->update([
                    'available_balance' => $newWalletBalance,
                    'updated_at'        => now()
                ]);

                // Deduct goal balance
                $newGoalAmount = bcsub($goal->current_amount, $amount, 2);
                $goal->update([
                    'current_amount' => $newGoalAmount
                ]);

                // Create transaction history on the main wallet
                $tx = Transaction::create([
                    'id'                      => (string) Str::uuid7(),
                    'user_id'                 => $userId,
                    'wallet_id'               => $sourceWalletId,
                    'type'                    => 'income',
                    'status'                  => 'completed',
                    'amount'                  => $amount,
                    'amount_in_user_currency' => $amount,
                    'currency_code'           => 'VND',
                    'exchange_rate'           => 1.00,
                    'title'                   => 'Nhận từ heo đất: ' . $goal->name,
                    'notes'                   => $notes,
                    'transaction_date'        => now(),
                    'source_type'             => 'transfer',
                    'source_id'               => $goal->id
                ]);

                // Create savings transaction log
                $savingsTx = SavingsTransaction::create([
                    'id'               => (string) Str::uuid7(),
                    'savings_goal_id'  => $goal->id,
                    'type'             => 'withdraw',
                    'amount'           => $amount,
                    'source_wallet_id' => $sourceWalletId,
                    'transaction_date' => now(),
                    'notes'            => $notes
                ]);

                return ['goal' => $goal, 'savings_tx' => $savingsTx];
            });

            $result['goal']->load(['sourceWallet:id,name', 'transactions' => function ($query) {
                $query->orderBy('transaction_date', 'desc');
            }]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Rút tiền từ mục tiêu tiết kiệm thành công.',
                'data'    => $result['goal']
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Xóa mục tiêu tiết kiệm
     * DELETE /api/savings/{id}
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $goal = SavingsGoal::where('id', $id)->where('user_id', $userId)->first();
            if (!$goal) {
                return response()->json(['status' => 'error', 'message' => 'Mục tiêu tiết kiệm không tồn tại hoặc bạn không có quyền.'], 404);
            }

            if (floatval($goal->current_amount) > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Vui lòng rút toàn bộ số dư khỏi heo đất trước khi xóa mục tiêu này.'
                ], 400);
            }

            $goal->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Xóa mục tiêu tiết kiệm thành công.'
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
