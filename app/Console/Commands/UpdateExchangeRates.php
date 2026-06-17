<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateExchangeRates extends Command
{
    /**
     * Tên và chữ ký của console command.
     *
     * @var string
     */
    protected $signature = 'rates:update';

    /**
     * Mô tả của console command.
     *
     * @var string
     */
    protected $description = 'Cập nhật tỷ giá hối đoái từ Frankfurter và Vietcombank vào Cache';

    /**
     * Thực thi console command.
     */
    public function handle(ExchangeRateService $exchangeRateService): int
    {
        $this->info('Bắt đầu cập nhật tỷ giá hối đoái ngầm...');
        
        try {
            // Lấy trực tiếp từ nguồn API (không thông qua cache đệm)
            $vcbRates = $exchangeRateService->fetchVcbRates();
            $latestRates = $exchangeRateService->fetchLatestRates();
            
            // Tự động làm giàu tỷ giá VND lấy trực tiếp từ Vietcombank USD Transfer Rate
            if (isset($vcbRates['USD']['buy_transfer'])) {
                $latestRates['VND'] = (float)$vcbRates['USD']['buy_transfer'];
            }
            
            // Ghi đè nguyên tử trực tiếp vào Cache với TTL 12 giờ (43200 giây)
            Cache::put('vcb_exchange_rates', $vcbRates, 43200);
            Cache::put('latest_exchange_rates', $latestRates, 43200);
            
            $this->info('Cập nhật tỷ giá thành công!');
            Log::info('Cron: Cập nhật tỷ giá hối đoái thành công.', [
                'currencies_count' => count($latestRates),
                'vcb_currencies_count' => count($vcbRates)
            ]);
            
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Lỗi khi cập nhật tỷ giá: ' . $e->getMessage());
            Log::error('Cron: Lỗi khi cập nhật tỷ giá hối đoái ngầm: ' . $e->getMessage());
            
            return Command::FAILURE;
        }
    }
}
