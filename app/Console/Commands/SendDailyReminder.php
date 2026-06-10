<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DailyReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendDailyReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:daily-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gửi email nhắc nhở ghi chép chi tiêu cuối ngày nếu người dùng chưa nhập giao dịch nào';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Bắt đầu gửi nhắc nhở ghi chép chi tiêu...');

        // Lấy tất cả user đang hoạt động
        $users = User::where('status', 'active')->get();
        $sentCount = 0;

        foreach ($users as $user) {
            // Chỉ gửi cho user có email hợp lệ
            if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Kiểm tra cấu hình preferences của user
            $preference = DB::table('notification_preferences')
                ->where('user_id', $user->user_id)
                ->first();

            // Nếu user tắt daily reminder thì bỏ qua
            if ($preference && isset($preference->daily_reminder_enabled) && !$preference->daily_reminder_enabled) {
                continue;
            }

            // Lấy timezone của user để tính ngày hôm nay
            $timezone = DB::table('user_preferences')
                ->where('user_id', $user->user_id)
                ->value('timezone') ?? 'Asia/Ho_Chi_Minh';

            $todayStart = Carbon::now($timezone)->startOfDay()->utc();
            $todayEnd = Carbon::now($timezone)->endOfDay()->utc();

            // Kiểm tra xem hôm nay user đã ghi chép giao dịch nào chưa
            $hasTransactions = DB::table('transactions')
                ->where('user_id', $user->user_id)
                ->whereBetween('transaction_date', [$todayStart, $todayEnd])
                ->whereNull('deleted_at')
                ->exists();

            if (!$hasTransactions) {
                // Gửi thông báo nhắc nhở
                $user->notify(new DailyReminderNotification());
                $sentCount++;
            }
        }

        $this->info("Đã gửi {$sentCount} thông báo nhắc nhở.");
        return Command::SUCCESS;
    }
}
