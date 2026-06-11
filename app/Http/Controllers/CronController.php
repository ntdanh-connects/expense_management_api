<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class CronController extends Controller
{
    /**
     * Endpoint công cộng để các dịch vụ ping (UptimeRobot, cron-job.org) kích hoạt Scheduler.
     * url: GET /api/cron-trigger?secret=your_secret_key
     */
    public function trigger(Request $request)
    {
        $configuredSecret = env('CRON_SECRET', 'expense_cron_secure_key_2026');
        $providedSecret = $request->query('secret');

        if ($providedSecret !== $configuredSecret) {
            Log::warning('Yêu cầu kích hoạt Cron bị từ chối do sai mã bí mật.');
            return response()->json([
                'status' => 'error',
                'message' => 'Truy cập bị chặn! Mã bí mật không chính xác.'
            ], 403);
        }

        Log::info('Bắt đầu kích hoạt Laravel Scheduler từ Web Cron.');

        try {
            // Chạy các lệnh schedule đã lên lịch (quét giao dịch định kỳ, gửi báo cáo...)
            Artisan::call('schedule:run');
            $output = Artisan::output();

            return response()->json([
                'status' => 'success',
                'message' => 'Kích hoạt Scheduler thành công.',
                'output' => trim($output)
            ]);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi chạy Scheduler qua API: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Lỗi hệ thống khi chạy Scheduler.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
