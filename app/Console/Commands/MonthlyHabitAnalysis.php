<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AIHabitAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MonthlyHabitAnalysis extends Command
{
    protected $signature = 'notification:monthly-habit-analysis';
    protected $description = 'Phân tích thói quen chi tiêu hàng tháng của người dùng và lưu vào database';

    public function handle(AIHabitAnalysisService $analysisService)
    {
        $this->info('Bắt đầu phân tích thói quen hàng tháng...');
        $users = User::where('status', 'active')->get();

        foreach ($users as $user) {
            $timezone = DB::table('user_preferences')->where('user_id', $user->user_id)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
            $today = Carbon::today($timezone);

            try {
                $analysisService->generateMonthlyAnalysis($user->user_id, $today);
            } catch (\Throwable $e) {
                $this->error("Lỗi phân tích thói quen hàng tháng của user {$user->user_id}: " . $e->getMessage());
            }
        }

        $this->info('Đã hoàn thành phân tích hàng tháng.');
        return Command::SUCCESS;
    }
}
