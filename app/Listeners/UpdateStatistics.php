<?php

namespace App\Listeners;

use App\Events\TransactionSaved;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateStatistics
{
    /**
     * Handle the event.
     */
    public function handle(TransactionSaved $event): void
    {
        $transaction = $event->transaction;
        $oldData = $event->oldData;
        $isDeleted = $event->isDeleted;

        if ($isDeleted) {
            // Trường hợp XÓA giao dịch: trừ đi số tiền của giao dịch đó
            $this->applyDifference(
                $transaction->user_id,
                $transaction->transaction_date,
                $transaction->category_id,
                $transaction->type,
                -1 * (float) $transaction->amount_in_user_currency
            );
        } elseif ($oldData !== null) {
            // Trường hợp CẬP NHẬT giao dịch:
            // 1. Trừ đi giá trị cũ
            $oldUserId = $oldData['user_id'] ?? $transaction->user_id;
            $oldDate = Carbon::parse($oldData['transaction_date'] ?? $transaction->transaction_date);
            $oldCategoryId = array_key_exists('category_id', $oldData) ? $oldData['category_id'] : $transaction->category_id;
            $oldType = $oldData['type'] ?? $transaction->type;
            $oldAmount = (float) ($oldData['amount_in_user_currency'] ?? $transaction->amount_in_user_currency);

            $this->applyDifference($oldUserId, $oldDate, $oldCategoryId, $oldType, -1 * $oldAmount);

            // 2. Cộng thêm giá trị mới
            $this->applyDifference(
                $transaction->user_id,
                $transaction->transaction_date,
                $transaction->category_id,
                $transaction->type,
                (float) $transaction->amount_in_user_currency
            );
        } else {
            // Trường hợp TẠO MỚI giao dịch: cộng thêm số tiền
            $this->applyDifference(
                $transaction->user_id,
                $transaction->transaction_date,
                $transaction->category_id,
                $transaction->type,
                (float) $transaction->amount_in_user_currency
            );
        }
    }

    /**
     * Áp dụng chênh lệch số tiền vào các bảng thống kê
     */
    private function applyDifference(string $userId, $date, ?string $categoryId, string $type, float $amountDiff): void
    {
        if ($amountDiff == 0) {
            return;
        }

        $carbonDate = Carbon::parse($date);
        $dateStr = $carbonDate->toDateString();
        $month = $carbonDate->month;
        $year = $carbonDate->year;

        // Tính toán thu nhập và chi tiêu chênh lệch
        $incomeDiff = $type === 'income' ? $amountDiff : 0;
        $expenseDiff = $type === 'expense' ? $amountDiff : 0;

        // 1. Cập nhật daily_statistics
        DB::statement("
            INSERT INTO daily_statistics (user_id, date, income, expense, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON CONFLICT (user_id, date) DO UPDATE
            SET income = COALESCE(daily_statistics.income, 0) + EXCLUDED.income,
                expense = COALESCE(daily_statistics.expense, 0) + EXCLUDED.expense,
                updated_at = NOW()
        ", [$userId, $dateStr, $incomeDiff, $expenseDiff]);

        // 2. Cập nhật monthly_statistics
        DB::statement("
            INSERT INTO monthly_statistics (user_id, month, year, income, expense, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON CONFLICT (user_id, month, year) DO UPDATE
            SET income = COALESCE(monthly_statistics.income, 0) + EXCLUDED.income,
                expense = COALESCE(monthly_statistics.expense, 0) + EXCLUDED.expense,
                updated_at = NOW()
        ", [$userId, $month, $year, $incomeDiff, $expenseDiff]);

        // 3. Cập nhật category_statistics (Chỉ khi có category_id hợp lệ vì cột này thuộc Primary Key nên không được NULL)
        if ($categoryId !== null) {
            DB::statement("
                INSERT INTO category_statistics (user_id, category_id, month, year, total_amount, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON CONFLICT (user_id, category_id, month, year) DO UPDATE
                SET total_amount = COALESCE(category_statistics.total_amount, 0) + EXCLUDED.total_amount,
                    updated_at = NOW()
            ", [$userId, $categoryId, $month, $year, $amountDiff]);
        }
    }
}
