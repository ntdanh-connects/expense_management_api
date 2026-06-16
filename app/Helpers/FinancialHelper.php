<?php

namespace App\Helpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialHelper
{
    /**
     * Lấy khoảng thời gian chu kỳ tháng tài chính (start, end) dựa theo calendar month/year
     *
     * @param string $userId
     * @param int $month
     * @param int $year
     * @return array [Carbon $startDateUtc, Carbon $endDateUtc]
     */
    public static function getFinancialRangeForMonth(string $userId, int $month, int $year): array
    {
        $preference = DB::table('user_preferences')->where('user_id', $userId)->first();
        $timezone = $preference->timezone ?? 'Asia/Ho_Chi_Minh';
        $financialStartDay = $preference->financial_start_day ?? 1;

        // Bắt đầu từ ngày financialStartDay của tháng, 00:00:00 theo timezone của user
        $startDate = Carbon::create($year, $month, $financialStartDay, 0, 0, 0, $timezone);

        if ($financialStartDay > 1) {
            // Kết thúc ở ngày (financialStartDay - 1) của tháng tiếp theo, 23:59:59
            $endDate = (clone $startDate)->addMonth()->day($financialStartDay - 1)->endOfDay();
        } else {
            // Kết thúc ở cuối tháng hiện tại
            $endDate = (clone $startDate)->endOfMonth();
        }

        return [
            $startDate->setTimezone('UTC'),
            $endDate->setTimezone('UTC')
        ];
    }

    /**
     * Lấy khoảng thời gian chu kỳ tài chính chứa ngày cụ thể
     *
     * @param string $userId
     * @param Carbon $date
     * @return array [Carbon $startDateUtc, Carbon $endDateUtc]
     */
    public static function getFinancialRangeForDate(string $userId, Carbon $date): array
    {
        $preference = DB::table('user_preferences')->where('user_id', $userId)->first();
        $timezone = $preference->timezone ?? 'Asia/Ho_Chi_Minh';
        $financialStartDay = $preference->financial_start_day ?? 1;

        $dateInUserTz = $date->copy()->setTimezone($timezone);

        if ($financialStartDay > 1) {
            if ($dateInUserTz->day < $financialStartDay) {
                // Thuộc về chu kỳ bắt đầu từ tháng trước
                $startDate = (clone $dateInUserTz)->subMonth()->day($financialStartDay)->startOfDay();
                $endDate = (clone $dateInUserTz)->day($financialStartDay - 1)->endOfDay();
            } else {
                // Thuộc về chu kỳ bắt đầu từ tháng này
                $startDate = (clone $dateInUserTz)->day($financialStartDay)->startOfDay();
                $endDate = (clone $dateInUserTz)->addMonth()->day($financialStartDay - 1)->endOfDay();
            }
        } else {
            $startDate = (clone $dateInUserTz)->startOfMonth();
            $endDate = (clone $dateInUserTz)->endOfMonth();
        }

        return [
            $startDate->setTimezone('UTC'),
            $endDate->setTimezone('UTC')
        ];
    }

    /**
     * Lấy tháng và năm tài chính (tương ứng với tháng bắt đầu chu kỳ) chứa ngày cụ thể
     *
     * @param string $userId
     * @param Carbon $date
     * @return array [int $month, int $year]
     */
    public static function getFinancialMonthAndYearForDate(string $userId, Carbon $date): array
    {
        $preference = DB::table('user_preferences')->where('user_id', $userId)->first();
        $timezone = $preference->timezone ?? 'Asia/Ho_Chi_Minh';
        $financialStartDay = $preference->financial_start_day ?? 1;

        $dateInUserTz = $date->copy()->setTimezone($timezone);

        if ($financialStartDay > 1 && $dateInUserTz->day < $financialStartDay) {
            // Thuộc về chu kỳ bắt đầu từ tháng lịch dương trước đó
            $cycleStart = (clone $dateInUserTz)->subMonth();
        } else {
            $cycleStart = $dateInUserTz;
        }

        return [
            (int) $cycleStart->format('m'),
            (int) $cycleStart->format('Y')
        ];
    }
}
