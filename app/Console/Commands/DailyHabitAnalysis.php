<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AIHabitAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyHabitAnalysis extends Command
{
    protected $signature = 'notification:daily-habit-analysis';
    protected $description = 'Phân tích thói quen chi tiêu hàng ngày của người dùng và lưu vào database';

    public function handle(AIHabitAnalysisService $analysisService)
    {
        $this->info('Bắt đầu phân tích thói quen hàng ngày...');
        $users = User::where('status', 'active')->get();

        foreach ($users as $user) {
            $timezone = DB::table('user_preferences')->where('user_id', $user->user_id)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
            $today = Carbon::today($timezone);

            try {
                $analysisService->generateDailyAnalysis($user->user_id, $today);
            } catch (\Throwable $e) {
                $this->error("Lỗi phân tích thói quen hàng ngày của user {$user->user_id}: " . $e->getMessage());
            }
        }

        $this->info('Đã hoàn thành phân tích hàng ngày.');
        return Command::SUCCESS;
    }
}
