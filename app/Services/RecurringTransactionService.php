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
        return RecurringRule::with(['wallet', 'category'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getRuleById(string $id, string $userId)
    {
        $rule = RecurringRule::with(['wallet', 'category'])->find($id);

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

        // 3. Tạo luật định kỳ
        return RecurringRule::create([
            'user_id' => $userId,
            'wallet_id' => $data['wallet_id'],
            'category_id' => $data['category_id'] ?? null,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'title' => $data['title'],
            'frequency' => $data['frequency'], // daily, weekly, monthly, yearly
            'interval_value' => $data['interval_value'] ?? 1,
            'next_run_at' => $data['next_run_at'] ?? now(),
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
            try {
                DB::transaction(function () use ($rule, &$executedCount) {
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

                    // 2. PHƯƠNG ÁN 2: Kiểm tra số dư ví nếu là giao dịch Chi tiêu (Expense)
                    if ($type === 'expense' && bccomp($walletBalance->available_balance, $amount, 2) === -1) {
                        // Ghi nhận lịch sử chạy thất bại và tiếp tục
                        RecurringExecution::create([
                            'id' => (string) Str::uuid7(),
                            'recurring_rule_id' => $ruleId,
                            'transaction_id' => null,
                            'executed_at' => now(),
                            'status' => 'failed',
                            'error_message' => __('messages.recurring_execution_insufficient_balance')
                        ]);

                        // Cập nhật ngày chạy tiếp theo để không bị lặp vô hạn ở ngày cũ
                        $this->advanceNextRunDate($rule);
                        return; // Thoát khỏi transaction của rule này
                    }

                    // 3. Thực hiện giao dịch
                    $transactionId = (string) Str::uuid7();
                    $userCurrency = DB::table('user_preferences')->where('user_id', $userId)->value('currency') ?? 'VND';

                    // Cập nhật số dư ví
                    $newBalance = 0;
                    if ($type === 'expense') {
                        $newBalance = bcsub($walletBalance->available_balance, $amount, 2);
                    } else {
                        $newBalance = bcadd($walletBalance->available_balance, $amount, 2);
                    }

                    // Tạo giao dịch định kỳ
                    $transaction = Transaction::create([
                        'id' => $transactionId,
                        'user_id' => $userId,
                        'wallet_id' => $walletId,
                        'category_id' => $rule->category_id,
                        'type' => $type,
                        'status' => 'completed',
                        'amount' => $amount,
                        'currency_code' => $userCurrency,
                        'exchange_rate' => 1.000000,
                        'amount_in_user_currency' => $amount,
                        'title' => $rule->title,
                        'notes' => __('messages.recurring_default_notes'),
                        'transaction_date' => now(),
                        'source_type' => 'recurring',
                        'source_id' => $ruleId
                    ]);

                    // Cập nhật số dư ví
                    DB::table('wallet_balances')->where('wallet_id', $walletId)->update([
                        'available_balance' => $newBalance,
                        'last_transaction_id' => $transactionId,
                        'updated_at' => now()
                    ]);

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

                    // 4. Tính toán ngày chạy tiếp theo
                    $this->advanceNextRunDate($rule);
                    $executedCount++;
                });
            } catch (\Throwable $e) {
                Log::error("Lỗi khi xử lý giao dịch định kỳ cho rule {$rule->id}: " . $e->getMessage());

                // Ghi nhận lịch sử chạy thất bại ra ngoài transaction để không bị rollback
                try {
                    RecurringExecution::create([
                        'id' => (string) Str::uuid7(),
                        'recurring_rule_id' => $rule->id,
                        'transaction_id' => null,
                        'executed_at' => now(),
                        'status' => 'failed',
                        'error_message' => $e->getMessage()
                    ]);

                    // Vẫn phải dịch ngày để tránh bị tắc nghẽn vô hạn ở mốc thời gian cũ
                    $this->advanceNextRunDate($rule);
                } catch (\Throwable $ex) {
                    Log::error("Không thể ghi log RecurringExecution thất bại: " . $ex->getMessage());
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
                $nextRun->addMonths($interval);
                break;
            case 'yearly':
                $nextRun->addYears($interval);
                break;
            default:
                $nextRun->addMonths(1);
        }

        $rule->next_run_at = $nextRun;

        // Nếu ngày chạy tiếp theo vượt quá ngày kết thúc, tắt hoạt động quy tắc định kỳ này
        if ($rule->end_at && $nextRun->greaterThan(Carbon::parse($rule->end_at))) {
            $rule->is_active = false;
        }

        $rule->save();
    }
}
