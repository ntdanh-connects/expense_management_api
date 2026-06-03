<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    // Cấu hình các đồng tiền được ứng dụng hỗ trợ chính thức
    protected $supportedCurrencies = ['VND', 'USD', 'EUR', 'GBP', 'JPY'];

    // Mốc tỷ giá dự phòng (Fallback) khi API ngoài bị lỗi hoặc timeout
    protected $fallbackRates = [
        'USD' => 1.0,
        'VND' => 25400.0,
        'EUR' => 0.92,
        'GBP' => 0.78,
        'JPY' => 156.0,
    ];

    /**
     * Lấy tỷ giá mới nhất quy đổi từ gốc USD.
     * Áp dụng cơ chế Cache trong 12 giờ.
     */
    public function getLatestRates(): array
    {
        return Cache::remember('latest_exchange_rates', 43200, function () {
            try {
                // Thử gọi API chính
                $response = Http::timeout(5)->get('https://api.frankfurter.dev/v1/latest', [
                    'from' => 'USD'
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    return $this->formatRates($data['rates'] ?? []);
                }

                // Nếu API chính lỗi, thử gọi API dự phòng
                $fallbackResponse = Http::timeout(5)->get('https://api.frankfurter.app/latest', [
                    'from' => 'USD'
                ]);

                if ($fallbackResponse->successful()) {
                    $data = $fallbackResponse->json();
                    return $this->formatRates($data['rates'] ?? []);
                }

            } catch (\Throwable $e) {
                Log::error('Lỗi kết nối API tỷ giá hối đoái, sử dụng cấu hình dự phòng:', [
                    'error' => $e->getMessage()
                ]);
            }

            // Trả về dữ liệu dự phòng nếu mọi cuộc gọi đều thất bại
            return $this->fallbackRates;
        });
    }

    /**
     * Lấy tỷ giá quy đổi giữa 2 đồng tiền bất kỳ.
     */
    public function getRate(string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return 1.0;
        }

        $rates = $this->getLatestRates();

        // Kiểm tra tính hợp lệ của đồng tiền
        if (!isset($rates[$from]) || !isset($rates[$to])) {
            Log::warning("Đồng tiền không được hỗ trợ hoặc thiếu dữ liệu tỷ giá: from=$from, to=$to");
            return 1.0;
        }

        // Quy đổi chéo qua trung gian USD:
        // USD -> FROM: $rates[$from]
        // USD -> TO: $rates[$to]
        // FROM -> TO: $rates[$to] / $rates[$from]
        return (float) ($rates[$to] / $rates[$from]);
    }

    /**
     * Quy đổi số tiền từ một đồng tiền sang đồng tiền khác.
     */
    public function convert(float $amount, string $from, string $to): float
    {
        $rate = $this->getRate($from, $to);
        return (float) bcmul($amount, $rate, 4);
    }

    /**
     * Lọc và định dạng chỉ lưu các đồng tiền được ứng dụng hỗ trợ chính thức.
     */
    protected function formatRates(array $apiRates): array
    {
        $formatted = ['USD' => 1.0];

        foreach ($this->supportedCurrencies as $currency) {
            if ($currency === 'USD') continue;

            if (isset($apiRates[$currency])) {
                $formatted[$currency] = (float) $apiRates[$currency];
            } else {
                $formatted[$currency] = $this->fallbackRates[$currency];
            }
        }

        return $formatted;
    }

    /**
     * Lấy danh sách các đồng tiền được hỗ trợ.
     */
    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }
}
