<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FinancialAnalysisService{

    //Lấy lịch sử dòng tiền tích lũy cuối ngày 
    public function getCashflowHistory(string $userId, string $periodStart, string $today, string $timezone):array{
        $sql = "
            WITH current_balances AS (
                SELECT COALESCE(SUM(wb.available_balance), 0) AS total
                FROM wallet_balances wb
                JOIN wallets w ON wb.wallet_id = w.id
                WHERE w.user_id = :user_id_1 AND w.deleted_at IS NULL
            ),
            future_net_by_date AS (
                SELECT
                    (t.transaction_date AT TIME ZONE 'UTC' AT TIME ZONE :timezone_1)::date AS tx_date,
                    SUM(CASE
                        WHEN t.type = 'income' THEN t.amount_in_user_currency
                        WHEN t.type = 'expense' THEN -t.amount_in_user_currency
                        ELSE 0
                    END) AS daily_net
                FROM transactions t
                WHERE t.user_id = :user_id_2
                AND t.deleted_at IS NULL
                AND t.status = 'completed'
                AND (t.source_type != 'transfer' OR t.source_type IS NULL)
                GROUP BY tx_date
            ),
            running_future AS (
                SELECT
                    tx_date,
                    SUM(daily_net) OVER (
                        ORDER BY tx_date DESC
                        ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                    ) AS net_after
                FROM future_net_by_date
            )
            SELECT
                d.date,
                COALESCE(d.income, 0) as daily_income,
                COALESCE(d.expense, 0) as daily_expense,
                (cb.total - COALESCE(rf.net_after,0)) AS balance_at_eod
            FROM daily_statistics d
            CROSS JOIN current_balances cb
            LEFT JOIN running_future rf ON rf.tx_date = d.date
            WHERE d.user_id = :user_id_3
                AND d.date BETWEEN :period_start AND :today
            ORDER BY d.date ASC
        ";
        return DB::select($sql, [
            'user_id_1' => $userId,
            'user_id_2' => $userId,
            'user_id_3' => $userId,
            'timezone_1' => $timezone,
            'period_start' => $periodStart,
            'today' => $today
        ]);
    }
}