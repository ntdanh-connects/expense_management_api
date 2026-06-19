<?php

namespace App\Http\Controllers;

use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferenceOptionsController extends Controller
{
    protected $exchangeRateService;

    public function __construct(ExchangeRateService $exchangeRateService)
    {
        $this->exchangeRateService = $exchangeRateService;
    }

    /**
     * Lấy các tùy chọn đơn vị tiền tệ và múi giờ hệ thống (GET /api/user/preferences/options)
     */
    public function getOptions(Request $request): JsonResponse
    {
        try {
            $currencies = [
                [
                    'code'    => 'VND',
                    'name'    => 'Việt Nam Đồng',
                    'symbol'  => '₫',
                    'decimal' => 0
                ]
            ];

            // Danh sách toàn bộ múi giờ thế giới chuẩn PHP
            $timezones = timezone_identifiers_list();

            // Danh sách ngôn ngữ được hỗ trợ
            $languages = [
                ['code' => 'vi', 'name' => 'Tiếng Việt'],
                ['code' => 'en', 'name' => 'English']
            ];

            // Giao diện hỗ trợ
            $themes = [
                ['code' => 'light', 'name' => 'Sáng (Light)'],
                ['code' => 'dark', 'name' => 'Tối (Dark)']
            ];

            return response()->json([
                'status'  => 'success',
                'message' => 'Lấy danh sách tùy chọn cấu hình hệ thống thành công!',
                'data'    => [
                    'currencies' => $currencies,
                    'timezones'  => $timezones,
                    'languages'  => $languages,
                    'themes'     => $themes
                ]
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Lấy danh sách cấu hình thất bại!',
                'error'   => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Lấy tỷ giá hối đoái hiện tại của các đồng tiền (GET /api/exchange-rates)
     */
    public function getRates(Request $request): JsonResponse
    {
        try {
            $rates = $this->exchangeRateService->getLatestRates();
            $vcbRates = $this->exchangeRateService->getVcbRates();

            return response()->json([
                'status'  => 'success',
                'message' => 'Lấy danh sách tỷ giá hối đoái mới nhất thành công!',
                'data'    => [
                    'base'  => 'USD',
                    'rates' => $rates,
                    'vcb_rates' => $vcbRates
                ]
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Lấy tỷ giá hối đoái thất bại!',
                'error'   => $e->getMessage()
            ], 400);
        }
    }
}
