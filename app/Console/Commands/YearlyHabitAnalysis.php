<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AIHabitAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class YearlyHabitAnalysis extends Command
{
    protected $signature = 'notification:yearly-habit-analysis';
    protected $description = 'Phân tích thói quen chi tiêu hàng năm của người dùng và lưu vào database';

    public function handle(AIHabitAnalysisService $analysisService)
    {
        $this->info('Bắt đầu phân tích thói quen hàng năm...');
        $users = User::where('status', 'active')->get();

        foreach ($users as $user) {
            $timezone = DB::table('user_preferences')->where('user_id', $user->user_id)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
            $today = Carbon::today($timezone);

            try {
                $analysisService->generateYearlyAnalysis($user->user_id, $today);
            } catch (\Throwable $e) {
                $this->error("Lỗi phân tích thói quen hàng năm của user {$user->user_id}: " . $e->getMessage());
            }
        }

        $this->info('Đã hoàn thành phân tích hàng năm.');
        return Command::SUCCESS;
    }
}
