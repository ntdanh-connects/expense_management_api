<?php

namespace App\Services;

use App\Repositories\Contracts\RecurringRuleRepositoryInterface;
use App\Models\RecurringRule;
use App\Models\RecurringExecution;
use App\Models\Transaction;
use App\Models\TransactionAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RecurringTransactionService
{
    protected $recurringRuleRepository;

    public function __construct(RecurringRuleRepositoryInterface $recurringRuleRepository)
    {
        $this->recurringRuleRepository = $recurringRuleRepository;
    }

    public function getAllRules(string $userId)
    {
        return RecurringRule::with(['wallet', 'category', 'payee'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getRuleById(string $id, string $userId)
    {
        $rule = RecurringRule::with(['wallet', 'category', 'payee'])->find($id);

        if (!$rule || $rule->user_id !== $userId) {
            throw new \Exception(__('messages.recurring_rule_not_found_or_unauthorized'));
        }

        return $rule;
    }

    public function createRule(string $userId, array $data)
    {
        // 1. Kiểm tra ví sở hữu
        $walletExists = DB::table('wallets')
            ->where('id', $data['wallet_id'])
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$walletExists) {
            throw new \Exception(__('messages.wallet_not_found_or_unauthorized'));
        }

        // 2. Kiểm tra danh mục sở hữu hoặc mặc định
        if (!empty($data['category_id'])) {
            $categoryExists = DB::table('categories')
                ->where('id', $data['category_id'])
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)
                      ->orWhere('is_default', true);
                })
                ->whereNull('deleted_at')
                ->exists();

            if (!$categoryExists) {
                throw new \Exception(__('messages.category_not_found_or_unauthorized'));
            }
        }

        // 2.5. Kiểm tra người hưởng thụ sở hữu
        if (!empty($data['payee_id'])) {
            $payeeExists = DB::table('saved_payees')
                ->where('id', $data['payee_id'])
                ->where('user_id', $userId)
                ->exists();

            if (!$payeeExists) {
                throw new \Exception(__('messages.payee_not_found_or_unauthorized'));
            }
        }

        // 3. Tạo luật định kỳ
        $nextRun = $data['next_run_at'] ?? now();
        return RecurringRule::create([
            'user_id' => $userId,
            'wallet_id' => $data['wallet_id'],
            'category_id' => $data['category_id'] ?? null,
            'payee_id' => $data['payee_id'] ?? null,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'title' => $data['title'],
            'frequency' => $data['frequency'], // daily, weekly, monthly, yearly
            'interval_value' => $data['interval_value'] ?? 1,
            'start_date' => $data['start_date'] ?? $nextRun,
            'next_run_at' => $nextRun,
            'end_at' => $data['end_at'] ?? null,
            'is_active' => $data['is_active'] ?? true
        ]);
    }

    public function updateRule(string $id, string $userId, array $data)
    {
        $rule = RecurringRule::find($id);

        if (!$rule || $rule->user_id !== $userId) {
            throw new \Exception(__('messages.recurring_rule_not_found_or_unauthorized'));
        }

        // Kiểm tra ví mới nếu thay đổi
        if (isset($data['wallet_id']) && $data['wallet_id'] !== $rule->wallet_id) {
            $walletExists = DB::table('wallets')
                ->where('id', $data['wallet_id'])
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->exists();

            if (!$walletExists) {
                throw new \Exception(__('messages.wallet_not_found_or_unauthorized'));
            }
        }

        // Kiểm tra danh mục mới nếu thay đổi
        if (isset($data['category_id']) && $data['category_id'] !== $rule->category_id) {
            $categoryExists = DB::table('categories')
                ->where('id', $data['category_id'])
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)
                      ->orWhere('is_default', true);
                })
                ->whereNull('deleted_at')
                ->exists();

            if (!$categoryExists) {
                throw new \Exception(__('messages.category_not_found_or_unauthorized'));
            }
        }

        // Kiểm tra người hưởng thụ mới nếu thay đổi
        if (isset($data['payee_id']) && $data['payee_id'] !== $rule->payee_id) {
            if (!empty($data['payee_id'])) {
                $payeeExists = DB::table('saved_payees')
                    ->where('id', $data['payee_id'])
                    ->where('user_id', $userId)
                    ->exists();

                if (!$payeeExists) {
                    throw new \Exception(__('messages.payee_not_found_or_unauthorized'));
                }
            }
        }

        $rule->update($data);

        return $rule;
    }

    public function deleteRule(string $id, string $userId)
    {
        $rule = RecurringRule::find($id);

        if (!$rule || $rule->user_id !== $userId) {
            throw new \Exception(__('messages.recurring_rule_not_found_or_unauthorized'));
        }

        return $rule->delete();
    }

    public function toggleRule(string $id, string $userId)
    {
        $rule = RecurringRule::find($id);

        if (!$rule || $rule->user_id !== $userId) {
            throw new \Exception(__('messages.recurring_rule_not_found_or_unauthorized'));
        }

        $rule->update([
            'is_active' => !$rule->is_active
        ]);

        return $rule;
    }

    /**
     * Tự động quét và thực thi các giao dịch định kỳ đến hạn
     */
    public function processRecurringRules()
    {
        $dueRules = $this->recurringRuleRepository->getActiveRulesDue();
        $executedCount = 0;

        foreach ($dueRules as $rule) {
            $user = $rule->user;
            if (!$user) {
                Log::warning("Không tìm thấy user cho rule {$rule->id}, bỏ qua.");
                continue;
            }

            while ($rule->is_active && $rule->next_run_at->lte(now())) {
                $currentRunAt = $rule->next_run_at;

                try {
                    $result = DB::transaction(function () use ($rule, $currentRunAt) {
                        $ruleId = $rule->id;
                        $userId = $rule->user_id;
                        $walletId = $rule->wallet_id;
                        $amount = (float) $rule->amount;
                        $type = $rule->type;

                        // 1. Khóa bảng số dư của ví
                        $walletBalance = DB::table('wallet_balances')
                            ->where('wallet_id', $walletId)
                            ->lockForUpdate()
                            ->first();

                        if (!$walletBalance) {
                            throw new \Exception(__('messages.wallet_balance_not_found'));
                        }

                        // 1.5. Kiểm tra xem người dùng đã tự nhập thủ công một giao dịch giống hệt trong ngày này chưa
                        $targetDate = Carbon::parse($currentRunAt);
                        $startOfDay = $targetDate->copy()->startOfDay();
                        $endOfDay = $targetDate->copy()->endOfDay();

                        $alreadyLogged = DB::table('transactions')
                            ->where('user_id', $userId)
                            ->where('wallet_id', $walletId)
                            ->where('type', $type)
                            ->where('amount', $amount)
                            ->where('title', $rule->title)
                            ->whereBetween('transaction_date', [$startOfDay, $endOfDay])
                            ->whereNull('deleted_at')
                            ->exists();

                        if ($alreadyLogged) {
                            // Ghi nhận lịch sử chạy là skipped
                            RecurringExecution::create([
                                'id' => (string) Str::uuid7(),
                                'recurring_rule_id' => $ruleId,
                                'transaction_id' => null,
                                'executed_at' => now(),
                                'status' => 'skipped',
                                'error_message' => 'Người dùng đã tự ghi nhận thủ công trong ngày này'
                            ]);

                            return [
                                'status' => 'skipped',
                                'error' => null,
                                'transaction' => null
                            ];
                        }

                        // 2. PHƯƠNG ÁN 2: Kiểm tra số dư ví nếu là giao dịch Chi tiêu (Expense)
                        if ($type === 'expense' && bccomp($walletBalance->available_balance, $amount, 2) === -1) {
                            // Ghi nhận lịch sử chạy thất bại
                            RecurringExecution::create([
                                'id' => (string) Str::uuid7(),
                                'recurring_rule_id' => $ruleId,
                                'transaction_id' => null,
                                'executed_at' => now(),
                                'status' => 'failed',
                                'error_message' => __('messages.recurring_execution_insufficient_balance')
                            ]);

                            return [
                                'status' => 'failed',
                                'error' => __('messages.recurring_execution_insufficient_balance'),
                                'transaction' => null
                            ];
                        }

                        // 3. Thực hiện giao dịch
                        $transactionId = (string) Str::uuid7();
                        $userCurrency = DB::table('user_preferences')->where('user_id', $userId)->value('currency') ?? 'VND';

                        // MỚI: Kiểm tra chuyển khoản nội bộ qua giao dịch định kỳ
                        $isP2P = false;
                        $recipientWallet = null;
                        $payee = null;
                        if ($type === 'expense') {
                            $wallet = DB::table('wallets')->where('id', $walletId)->first();
                            if ($wallet && in_array($wallet->type, ['bank', 'ewallet'])) {
                                if (!empty($rule->payee_id)) {
                                    $payee = DB::table('saved_payees')
                                        ->where('id', $rule->payee_id)
                                        ->where('user_id', $userId)
                                        ->first();
                                    if ($payee && $payee->payee_type === 'internal' && !empty($payee->payee_user_id)) {
                                        $isP2P = true;
                                        
                                        // Find recipient's default receiving wallet first (bank/ewallet in VND)
                                        $recipientWallet = DB::table('wallets')
                                            ->where('user_id', $payee->payee_user_id)
                                            ->whereIn('type', ['bank', 'ewallet'])
                                            ->where('currency_code', 'VND')
                                            ->where('is_default_receiving', true)
                                            ->whereNull('deleted_at')
                                            ->first();

                                        if (!$recipientWallet) {
                                            $recipientWallet = DB::table('wallets')
                                                ->where('user_id', $payee->payee_user_id)
                                                ->whereIn('type', ['bank', 'ewallet'])
                                                ->where('currency_code', 'VND')
                                                ->whereNull('deleted_at')
                                                ->first();
                                        }

                                        if (!$recipientWallet) {
                                            throw new \Exception(__('messages.qr_recipient_no_valid_wallet'));
                                        }
                                    }
                                }
                            }
                        }

                        // Cập nhật số dư ví
                        $newBalance = 0;
                        if ($type === 'expense') {
                            $newBalance = bcsub($walletBalance->available_balance, $amount, 2);
                        } else {
                            $newBalance = bcadd($walletBalance->available_balance, $amount, 2);
                        }

                        $transferId = $isP2P ? (string) Str::uuid7() : null;

                        $recurringTitle = $rule->title;
                        if ($isP2P && $payee) {
                            $recurringTitle = "Chuyển tiền định kỳ đến " . ($payee->payee_name ?? 'Người nhận');
                        } elseif ($payee && $payee->payee_type === 'external') {
                            $recurringTitle = "Thanh toán định kỳ cho " . ($payee->payee_name ?? 'Người thụ hưởng');
                        }

                        // Tạo giao dịch định kỳ người gửi (sender transaction)
                        $transaction = Transaction::create([
                            'id' => $transactionId,
                            'user_id' => $userId,
                            'wallet_id' => $walletId,
                            'category_id' => $rule->category_id,
                            'payee_id' => $rule->payee_id,
                            'type' => $type,
                            'status' => 'completed',
                            'amount' => $amount,
                            'currency_code' => $userCurrency,
                            'exchange_rate' => 1.000000,
                            'amount_in_user_currency' => $amount,
                            'title' => $recurringTitle,
                            'notes' => __('messages.recurring_default_notes'),
                            'transaction_date' => $currentRunAt, // Ghi nhận đúng ngày thực tế của chu kỳ
                            'source_type' => $isP2P ? 'transfer' : 'recurring',
                            'source_id' => $isP2P ? $transferId : $ruleId
                        ]);

                        // Cập nhật số dư ví người gửi
                        DB::table('wallet_balances')->where('wallet_id', $walletId)->update([
                            'available_balance' => $newBalance,
                            'last_transaction_id' => $transactionId,
                            'updated_at' => now()
                        ]);

                        if ($isP2P) {
                            $recipientTransactionId = (string) Str::uuid7();

                            $recipientCurrency = $recipientWallet->currency_code ?? 'VND';
                            $wallet = DB::table('wallets')->where('id', $walletId)->first();
                            $sourceCurrency = $wallet->currency_code ?? 'VND';
                            
                            $rateToRecipient = 1.000000;
                            if ($sourceCurrency !== $recipientCurrency) {
                                $exchangeRateService = app(\App\Services\ExchangeRateService::class);
                                $rateToRecipient = $exchangeRateService->getRate($sourceCurrency, $recipientCurrency);
                            }
                            $recipientAmount = (float) bcmul((string)$amount, sprintf('%.6f', $rateToRecipient), 4);

                            // Lock recipient balance
                            $recipientBalance = DB::table('wallet_balances')
                                ->where('wallet_id', $recipientWallet->id)
                                ->lockForUpdate()
                                ->first();
                            if (!$recipientBalance) {
                                throw new \Exception("Không tìm thấy thông tin số dư ví người nhận.");
                            }
                            $newRecipientBalance = bcadd($recipientBalance->available_balance, $recipientAmount, 2);

                            // Recipient amount in user currency
                            $recipientUserCurrency = DB::table('user_preferences')->where('user_id', $payee->payee_user_id)->value('currency') ?? 'VND';
                            $recipientAmountInUserCurrency = $recipientAmount;
                            if ($recipientCurrency !== $recipientUserCurrency) {
                                $exchangeRateService = app(\App\Services\ExchangeRateService::class);
                                $rateToRecipientUser = $exchangeRateService->getRate($recipientCurrency, $recipientUserCurrency);
                                $recipientAmountInUserCurrency = (float) bcmul((string)$recipientAmount, sprintf('%.6f', $rateToRecipientUser), 4);
                            }

                            $senderProfile = DB::table('user_profiles')->where('user_id', $userId)->first();
                            $senderName = $senderProfile ? $senderProfile->full_name : 'Người gửi';

                            // Create recipient transaction
                            Transaction::create([
                                'id' => $recipientTransactionId,
                                'user_id' => $payee->payee_user_id,
                                'wallet_id' => $recipientWallet->id,
                                'category_id' => null,
                                'payee_id' => null,
                                'type' => 'income',
                                'status' => 'completed',
                                'amount' => $recipientAmount,
                                'currency_code' => $recipientCurrency,
                                'exchange_rate' => $rateToRecipient,
                                'amount_in_user_currency' => $recipientAmountInUserCurrency,
                                'title' => "Nhận tiền định kỳ từ {$senderName}",
                                'notes' => __('messages.recurring_default_notes'),
                                'timezone' => DB::table('user_preferences')->where('user_id', $payee->payee_user_id)->value('timezone') ?? 'Asia/Ho_Chi_Minh',
                                'transaction_date' => $currentRunAt,
                                'source_type' => 'transfer',
                                'source_id' => $transferId
                            ]);

                            // Update recipient balance
                            DB::table('wallet_balances')->where('wallet_id', $recipientWallet->id)->update([
                                'available_balance' => $newRecipientBalance,
                                'last_transaction_id' => $recipientTransactionId,
                                'updated_at' => now()
                            ]);

                            // Create wallet transfer record
                            DB::table('wallet_transfers')->insert([
                                'id' => $transferId,
                                'from_wallet_id' => $walletId,
                                'to_wallet_id' => $recipientWallet->id,
                                'amount' => $amount,
                                'expense_transaction_id' => $transactionId,
                                'income_transaction_id' => $recipientTransactionId,
                                'transferred_at' => now(),
                                'timezone' => DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh',
                                'created_at' => now()
                            ]);
                        }

                        // Gửi thông báo chuyển khoản P2P
                        if ($isP2P) {
                            try {
                                $recipientUser = \App\Models\User::find($payee->payee_user_id);
                                if ($recipientUser) {
                                    $senderProfile = DB::table('user_profiles')->where('user_id', $userId)->first();
                                    $senderName = $senderProfile ? $senderProfile->full_name : 'Người gửi';
                                    $recipientUser->notify(new \App\Notifications\P2pTransferReceivedNotification(
                                        $senderName,
                                        $recipientAmount,
                                        $recipientCurrency,
                                        __('messages.recurring_default_notes')
                                    ));
                                }
                            } catch (\Throwable $e) {
                                Log::error("Lỗi khi gửi thông báo chuyển tiền P2P định kỳ: " . $e->getMessage());
                            }
                        }

                        // Bắn sự kiện TransactionSaved
                        event(new \App\Events\TransactionSaved($transaction));

                        // Ghi lịch sử chạy thành công
                        RecurringExecution::create([
                            'id' => (string) Str::uuid7(),
                            'recurring_rule_id' => $ruleId,
                            'transaction_id' => $transactionId,
                            'executed_at' => now(),
                            'status' => 'success',
                            'error_message' => null
                        ]);

                        // Ghi log audit
                        TransactionAudit::create([
                            'transaction_id' => $transactionId,
                            'old_data' => null,
                            'new_data' => $transaction->toArray(),
                            'changed_by' => $userId
                        ]);

                        return [
                            'status' => 'success',
                            'error' => null,
                            'transaction' => $transaction
                        ];
                    });

                    // Gửi thông báo
                    if ($result['status'] === 'success') {
                        $user->notify(new \App\Notifications\RecurringTransactionExecutedNotification($rule, $result['transaction'], 'success'));
                        $executedCount++;
                    } elseif ($result['status'] === 'skipped') {
                        // Bỏ qua không gửi thông báo vì người dùng đã tự ghi nhận thủ công
                    } else {
                        $user->notify(new \App\Notifications\RecurringTransactionExecutedNotification($rule, null, 'failed', $result['error']));
                    }

                    $this->advanceNextRunDate($rule);
                } catch (\Throwable $e) {
                    Log::error("Lỗi khi xử lý giao dịch định kỳ cho rule {$rule->id} tại chu kỳ {$currentRunAt}: " . $e->getMessage());

                    try {
                        RecurringExecution::create([
                            'id' => (string) Str::uuid7(),
                            'recurring_rule_id' => $rule->id,
                            'transaction_id' => null,
                            'executed_at' => now(),
                            'status' => 'failed',
                            'error_message' => $e->getMessage()
                        ]);

                        $user->notify(new \App\Notifications\RecurringTransactionExecutedNotification($rule, null, 'failed', $e->getMessage()));
                    } catch (\Throwable $ex) {
                        Log::error("Không thể ghi log RecurringExecution thất bại: " . $ex->getMessage());
                    }

                    $this->advanceNextRunDate($rule);
                }
            }
        }

        return $executedCount;
    }

    /**
     * Dịch ngày chạy tiếp theo của luật định kỳ
     */
    protected function advanceNextRunDate(RecurringRule $rule)
    {
        $anchorDate = Carbon::parse($rule->start_date ?? $rule->created_at ?? $rule->next_run_at);
        $anchorDay = $anchorDate->day;

        $nextRun = Carbon::parse($rule->next_run_at);
        $interval = $rule->interval_value ?? 1;

        switch ($rule->frequency) {
            case 'daily':
                $nextRun->addDays($interval);
                break;
            case 'weekly':
                $nextRun->addWeeks($interval);
                break;
            case 'monthly':
                $nextRun->day(1)->addMonthsNoOverflow($interval);
                $nextRun->day(min($anchorDay, $nextRun->daysInMonth));
                break;
            case 'yearly':
                $nextRun->day(1)->addYearsNoOverflow($interval);
                $nextRun->day(min($anchorDay, $nextRun->daysInMonth));
                break;
            default:
                $nextRun->day(1)->addMonthsNoOverflow(1);
                $nextRun->day(min($anchorDay, $nextRun->daysInMonth));
        }

        $rule->next_run_at = $nextRun;

        // Nếu ngày chạy tiếp theo vượt quá ngày kết thúc, tắt hoạt động quy tắc định kỳ này
        if ($rule->end_at && $nextRun->greaterThan(Carbon::parse($rule->end_at))) {
            $rule->is_active = false;
        }

        $rule->save();
    }
}
