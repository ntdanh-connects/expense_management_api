<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\FinancialMonthStartNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendFinancialMonthStartReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:financial-month-start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gửi thông báo bắt đầu tháng tài chính cho người dùng';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Bắt đầu kiểm tra ngày bắt đầu tháng tài chính...');
        Log::info('Bắt đầu gửi nhắc nhở ngày bắt đầu tháng tài chính...');

        // Lấy tất cả user đang hoạt động
        $users = User::where('status', 'active')->get();
        $sentCount = 0;

        foreach ($users as $user) {
            // Lấy timezone của user để tính ngày hôm nay
            $preference = DB::table('user_preferences')
                ->where('user_id', $user->user_id)
                ->first();

            $timezone = $preference->timezone ?? 'Asia/Ho_Chi_Minh';
            $financialStartDay = $preference->financial_start_day ?? 1;

            $now = Carbon::now($timezone);
            $todayDay = $now->day;
            
            // Kiểm tra xem hôm nay có phải ngày bắt đầu tháng tài chính không
            // Trường hợp ngày hôm nay bằng đúng financial_start_day
            // Hoặc là ngày cuối cùng của tháng và financial_start_day lớn hơn ngày cuối cùng của tháng (ví dụ: ngày 31 trong tháng có 30 ngày)
            $isStartDay = ($todayDay === $financialStartDay) || 
                          ($now->isLastOfMonth() && $financialStartDay > $todayDay);

            if ($isStartDay) {
                try {
                    $user->notify(new FinancialMonthStartNotification($financialStartDay));
                    $sentCount++;
                    Log::info("Đã gửi thông báo bắt đầu tháng tài chính cho user: {$user->user_id} (Ngày tài chính: {$financialStartDay})");
                } catch (\Throwable $e) {
                    Log::error("Lỗi khi gửi thông báo bắt đầu tháng tài chính cho user {$user->user_id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Đã gửi {$sentCount} thông báo bắt đầu tháng tài chính.");
        Log::info("Gửi thông báo bắt đầu tháng tài chính hoàn tất. Tổng số đã gửi: {$sentCount}");
        return Command::SUCCESS;
    }
}
