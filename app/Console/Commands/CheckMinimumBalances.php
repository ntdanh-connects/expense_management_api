<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Models\User;
use App\Notifications\MinimumBalanceWarningNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckMinimumBalances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:check-minimum-balances';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kiểm tra số dư các ví và gửi cảnh báo nếu số dư giảm dưới hạn mức tối thiểu';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Bắt đầu kiểm tra số dư ví tối thiểu...');

        // Lấy tất cả các ví đang kích hoạt cảnh báo và có thiết lập hạn mức
        $wallets = Wallet::whereNotNull('minimum_balance')
            ->where('is_minimum_balance_alert_enabled', true)
            ->whereNull('deleted_at')
            ->get();

        $alertCount = 0;

        foreach ($wallets as $wallet) {
            $balanceRecord = DB::table('wallet_balances')
                ->where('wallet_id', $wallet->id)
                ->first();

            if (!$balanceRecord) {
                continue;
            }

            $currentBalance = (float) $balanceRecord->available_balance;
            $minBalance = (float) $wallet->minimum_balance;

            if ($currentBalance < $minBalance) {
                if (empty($wallet->last_alert_sent_at)) {
                    $user = User::find($wallet->user_id);
                    if ($user && $user->status === 'active') {
                        $user->notify(new MinimumBalanceWarningNotification($wallet, $currentBalance));
                        
                        $wallet->update([
                            'last_alert_sent_at' => now()
                        ]);
                        
                        $alertCount++;
                    }
                }
            }
        }

        $this->info("Đã gửi {$alertCount} cảnh báo số dư thấp.");
        return Command::SUCCESS;
    }
}
