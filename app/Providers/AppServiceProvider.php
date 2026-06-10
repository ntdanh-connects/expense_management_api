<?php

namespace App\Providers;

require_once app_path('Helpers/bcmath_fallback.php');

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Listener UpdateBudgetUsage và UpdateStatistics được Laravel tự phát hiện
        // thông qua type-hint TransactionSaved trong method handle().
        // KHÔNG đăng ký thủ công ở đây để tránh bị gọi 2 lần → nhân đôi số liệu thống kê.
    }
}
