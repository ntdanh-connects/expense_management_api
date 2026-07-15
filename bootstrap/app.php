<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        
        // Cấu hình mở cổng cho Frontend
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->append(\App\Http\Middleware\LocalizationMiddleware::class);

        $middleware->alias([
            'custom.auth' => \App\Http\Middleware\CustomTokenAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('recurring:process')->everyMinute();
        // Nhắc nhở thêm chi tiêu hàng ngày vào lúc 21:00
        $schedule->command('notification:daily-reminder')->dailyAt('21:00');
        // Gửi báo cáo chi tiêu tuần vào lúc 20:00 ngày Chủ Nhật
        $schedule->command('notification:weekly-summary')->sundays()->at('20:00');
        // Gửi thông báo bắt đầu tháng tài chính mỗi ngày vào lúc 08:00
        $schedule->command('notification:financial-month-start')->dailyAt('08:00');
        // Cập nhật tỷ giá hối đoái ngầm từ Vietcombank & Frankfurter mỗi giờ
        $schedule->command('rates:update')->hourly();
        // Tích lũy tự động heo đất hàng ngày lúc 01:00
        $schedule->command('savings:auto-accumulate')->dailyAt('01:00');
        
        // Cảnh báo số dư ví hụt tiền tối thiểu hàng ngày lúc 08:00
        $schedule->command('notification:check-minimum-balances')->dailyAt('08:00');
        // Phân tích thói quen chi tiêu hàng ngày lúc 22:00
        $schedule->command('notification:daily-habit-analysis')->dailyAt('22:00');
        // Phân tích thói quen hàng tháng vào ngày cuối tháng lúc 23:00
        $schedule->command('notification:monthly-habit-analysis')->lastDayOfMonth('23:00');
        // Phân tích thói quen hàng năm vào ngày 31/12 lúc 23:00
        $schedule->command('notification:yearly-habit-analysis')->yearlyOn(12, 31, '23:00');
    })->create();

