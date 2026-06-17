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
        $rates = Cache::remember('latest_exchange_rates', 43200, function () {
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

        // Tự động làm giàu tỷ giá VND lấy trực tiếp từ Vietcombank USD Transfer Rate
        try {
            $vcbRates = $this->getVcbRates();
            if (isset($vcbRates['USD']['buy_transfer'])) {
                $rates['VND'] = (float)$vcbRates['USD']['buy_transfer'];
            }
        } catch (\Throwable $e) {
            Log::warning('Không thể cập nhật tỷ giá VND từ Vietcombank: ' . $e->getMessage());
        }

        return $rates;
    }

    /**
     * Lấy tỷ giá chi tiết từ Vietcombank (Tiền mặt, Chuyển khoản, Bán ra).
     * Áp dụng cơ chế Cache trong 12 giờ.
     */
    public function getVcbRates(): array
    {
        return Cache::remember('vcb_exchange_rates', 43200, function () {
            try {
                $response = Http::timeout(10)->get('https://portal.vietcombank.com.vn/Usercontrols/TVPortal.TyGia/pXML.aspx');
                if ($response->successful()) {
                    $xmlString = $response->body();
                    $xml = simplexml_load_string($xmlString);
                    if ($xml) {
                        $vcbRates = [];
                        
                        // Thêm VND làm cơ sở so sánh (tỷ lệ 1-1)
                        $vcbRates['VND'] = [
                            'currency_code' => 'VND',
                            'currency_name' => 'VIETNAMESE DONG',
                            'buy_cash' => 1.0,
                            'buy_transfer' => 1.0,
                            'sell' => 1.0,
                            'mid' => 1.0,
                            'buy_cash_fee_percent' => 0.0,
                            'buy_transfer_fee_percent' => 0.0,
                            'sell_fee_percent' => 0.0
                        ];

                        foreach ($xml->Exrate as $rate) {
                            $code = strtoupper(trim((string)$rate['CurrencyCode']));
                            
                            // Chỉ lưu trữ các đồng ngoại tệ được hệ thống hỗ trợ
                            if (!in_array($code, $this->supportedCurrencies)) {
                                continue;
                            }

                            $name = trim((string)$rate['CurrencyName']);
                            $buyCash = $this->parseFormattedNumber((string)$rate['Buy']);
                            $buyTransfer = $this->parseFormattedNumber((string)$rate['Transfer']);
                            $sell = $this->parseFormattedNumber((string)$rate['Sell']);

                            // Fallback nếu không có tỷ giá mua tiền mặt (INR, KWD...)
                            if ($buyCash <= 0 && $buyTransfer > 0) {
                                $buyCash = $buyTransfer;
                            }

                            if ($buyTransfer > 0 && $sell > 0) {
                                $mid = ($buyTransfer + $sell) / 2.0;
                                $buyCashFee = $mid > 0 ? (($mid - $buyCash) / $mid) * 100.0 : 0.0;
                                $buyTransferFee = $mid > 0 ? (($mid - $buyTransfer) / $mid) * 100.0 : 0.0;
                                $sellFee = $mid > 0 ? (($sell - $mid) / $mid) * 100.0 : 0.0;

                                $vcbRates[$code] = [
                                    'currency_code' => $code,
                                    'currency_name' => $name,
                                    'buy_cash' => $buyCash,
                                    'buy_transfer' => $buyTransfer,
                                    'sell' => $sell,
                                    'mid' => $mid,
                                    'buy_cash_fee_percent' => round($buyCashFee, 3),
                                    'buy_transfer_fee_percent' => round($buyTransferFee, 3),
                                    'sell_fee_percent' => round($sellFee, 3)
                                ];
                            }
                        }

                        if (count($vcbRates) > 1) { // Có thêm đồng ngoại tệ ngoài VND
                            return $vcbRates;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Lỗi khi lấy dữ liệu tỷ giá Vietcombank: ' . $e->getMessage());
            }

            return $this->getMockVcbRates();
        });
    }

    /**
     * Định dạng chuỗi số tỷ giá từ XML (bỏ dấu phẩy ngăn cách hàng nghìn).
     */
    private function parseFormattedNumber(string $value): float
    {
        $value = trim($value);
        if ($value === '-' || $value === '') {
            return 0.0;
        }
        $value = str_replace(',', '', $value);
        return (float)$value;
    }

    /**
     * Dữ liệu tỷ giá Vietcombank dự phòng (Mock data) khi API của Vietcombank gặp sự cố.
     */
    private function getMockVcbRates(): array
    {
        $vcbRates = [];
        
        // Cấu hình tỷ giá trung bình khớp thời điểm hiện tại
        $mockData = [
            'VND' => ['name' => 'VIETNAMESE DONG', 'buy_cash' => 1.0, 'buy_transfer' => 1.0, 'sell' => 1.0],
            'USD' => ['name' => 'US DOLLAR', 'buy_cash' => 26083.0, 'buy_transfer' => 26113.0, 'sell' => 26433.0],
            'EUR' => ['name' => 'EURO', 'buy_cash' => 29774.0, 'buy_transfer' => 30075.0, 'sell' => 31344.0],
            'GBP' => ['name' => 'POUND STERLING', 'buy_cash' => 34427.0, 'buy_transfer' => 34775.0, 'sell' => 35888.0],
            'JPY' => ['name' => 'YEN', 'buy_cash' => 158.37, 'buy_transfer' => 159.96, 'sell' => 168.42],
        ];

        foreach ($mockData as $code => $data) {
            $buyCash = $data['buy_cash'];
            $buyTransfer = $data['buy_transfer'];
            $sell = $data['sell'];
            $mid = ($buyTransfer + $sell) / 2.0;

            $buyCashFee = $mid > 0 ? (($mid - $buyCash) / $mid) * 100.0 : 0.0;
            $buyTransferFee = $mid > 0 ? (($mid - $buyTransfer) / $mid) * 100.0 : 0.0;
            $sellFee = $mid > 0 ? (($sell - $mid) / $mid) * 100.0 : 0.0;

            $vcbRates[$code] = [
                'currency_code' => $code,
                'currency_name' => $data['name'],
                'buy_cash' => $buyCash,
                'buy_transfer' => $buyTransfer,
                'sell' => $sell,
                'mid' => $mid,
                'buy_cash_fee_percent' => round($buyCashFee, 3),
                'buy_transfer_fee_percent' => round($buyTransferFee, 3),
                'sell_fee_percent' => round($sellFee, 3)
            ];
        }

        return $vcbRates;
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
