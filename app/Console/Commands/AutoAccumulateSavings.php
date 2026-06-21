<?php

namespace App\Console\Commands;

use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutoAccumulateSavings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'savings:auto-accumulate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tự động trích tiền từ ví nguồn vào ví tiết kiệm theo tần suất cài đặt';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Bắt đầu quét cấu hình tích lũy tự động...');

        $goals = SavingsGoal::where('status', 'active')
            ->whereNotNull('auto_save_frequency')
            ->where('auto_save_amount', '>', 0)
            ->whereNotNull('source_wallet_id')
            ->get();

        $processedCount = 0;
        $failedCount = 0;

        foreach ($goals as $goal) {
            try {
                if (!$this->isDue($goal)) {
                    continue;
                }

                $amount = floatval($goal->auto_save_amount);
                $sourceWalletId = $goal->source_wallet_id;
                $userId = $goal->user_id;
                $notes = 'Tích lũy tự động';

                DB::transaction(function () use ($userId, $goal, $amount, $sourceWalletId, $notes) {
                    // Lock goal row
                    $lockedGoal = SavingsGoal::where('id', $goal->id)->lockForUpdate()->first();
                    if ($lockedGoal->status !== 'active') {
                        return;
                    }

                    // Lock wallet balance
                    $walletBalance = DB::table('wallet_balances')->where('wallet_id', $sourceWalletId)->lockForUpdate()->first();
                    if (!$walletBalance) {
                        throw new \Exception("Không tìm thấy dữ liệu số dư cho ví nguồn ID: {$sourceWalletId}");
                    }

                    if (bccomp($walletBalance->available_balance, $amount, 2) === -1) {
                        throw new \Exception("Số dư ví nguồn không đủ để tự động tích lũy.");
                    }

                    // Deduct wallet balance
                    $newWalletBalance = bcsub($walletBalance->available_balance, $amount, 2);
                    DB::table('wallet_balances')->where('wallet_id', $sourceWalletId)->update([
                        'available_balance' => $newWalletBalance,
                        'updated_at'        => now()
                    ]);

                    // Add to goal balance
                    $newGoalAmount = bcadd($lockedGoal->current_amount, $amount, 2);
                    
                    // Check if reached target
                    $status = $lockedGoal->status;
                    if (bccomp($newGoalAmount, $lockedGoal->target_amount, 2) >= 0) {
                        $status = 'completed';
                    }

                    $lockedGoal->update([
                        'current_amount' => $newGoalAmount,
                        'status'         => $status
                    ]);

                    // Create main transaction
                    Transaction::create([
                        'id'                      => (string) Str::uuid7(),
                        'user_id'                 => $userId,
                        'wallet_id'               => $sourceWalletId,
                        'type'                    => 'expense',
                        'status'                  => 'completed',
                        'amount'                  => $amount,
                        'amount_in_user_currency' => $amount,
                        'currency_code'           => 'VND',
                        'exchange_rate'           => 1.00,
                        'title'                   => 'Tích lũy tự động heo đất: ' . $lockedGoal->name,
                        'notes'                   => $notes,
                        'transaction_date'        => now(),
                        'source_type'             => 'transfer',
                        'source_id'               => $lockedGoal->id
                    ]);

                    // Create savings transaction
                    SavingsTransaction::create([
                        'id'               => (string) Str::uuid7(),
                        'savings_goal_id'  => $lockedGoal->id,
                        'type'             => 'deposit',
                        'amount'           => $amount,
                        'source_wallet_id' => $sourceWalletId,
                        'transaction_date' => now(),
                        'notes'            => $notes
                    ]);
                });

                $this->info("Đã trích thành công {$amount}đ vào ví tiết kiệm '{$goal->name}'");
                $processedCount++;

            } catch (\Throwable $e) {
                $this->warn("Tích lũy tự động lỗi cho mục tiêu '{$goal->name}': " . $e->getMessage());
                $failedCount++;
            }
        }

        $this->info("Hoàn thành! Thành công: {$processedCount}, Thất bại: {$failedCount}");
        return Command::SUCCESS;
    }

    /**
     * Kiểm tra xem mục tiêu đã đến hạn tích lũy chưa
     */
    private function isDue(SavingsGoal $goal): bool
    {
        $frequency = $goal->auto_save_frequency;
        $query = SavingsTransaction::where('savings_goal_id', $goal->id)
            ->where('type', 'deposit')
            ->where('notes', 'like', 'Tích lũy tự động%');

        switch ($frequency) {
            case 'daily':
                return !$query->where('transaction_date', '>=', now()->startOfDay())->exists();
            case 'weekly':
                return !$query->where('transaction_date', '>=', now()->subDays(7)->startOfDay())->exists();
            case 'monthly':
                return !$query->where('transaction_date', '>=', now()->subDays(30)->startOfDay())->exists();
            default:
                return false;
        }
    }
}
