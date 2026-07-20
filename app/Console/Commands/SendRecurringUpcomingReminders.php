<?php

namespace App\Console\Commands;

use App\Models\RecurringRule;
use App\Notifications\RecurringUpcomingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendRecurringUpcomingReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:recurring-upcoming-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gửi thông báo nhắc nhở giao dịch định kỳ sắp tới hạn (trước 2 ngày và đúng ngày)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Bắt đầu kiểm tra và gửi thông báo giao dịch định kỳ sắp tới hạn...');

        $now = Carbon::now('Asia/Ho_Chi_Minh')->startOfDay();

        // Chỉ lấy các quy tắc hoạt động thuộc tần suất weekly, monthly, yearly (bỏ daily)
        $rules = RecurringRule::where('is_active', true)
            ->whereIn('frequency', ['weekly', 'monthly', 'yearly'])
            ->whereNotNull('next_run_at')
            ->whereNull('deleted_at')
            ->get();

        $sentCount = 0;

        foreach ($rules as $rule) {
            $user = $rule->user;
            if (!$user || $user->status !== 'active') {
                continue;
            }

            $nextRunDate = Carbon::parse($rule->next_run_at)->timezone('Asia/Ho_Chi_Minh')->startOfDay();
            $diffInDays = $now->diffInDays($nextRunDate, false);

            // Chỉ xử lý nếu còn 2 ngày, 1 ngày, hoặc đúng ngày hôm nay (diffInDays == 0)
            if (in_array((int)$diffInDays, [0, 1, 2], true)) {
                $user->notify(new RecurringUpcomingReminderNotification($rule, (int) $diffInDays));
                $sentCount++;
            }
        }

        $this->info("Đã gửi {$sentCount} thông báo nhắc nhở giao dịch định kỳ.");
        return Command::SUCCESS;
    }
}
