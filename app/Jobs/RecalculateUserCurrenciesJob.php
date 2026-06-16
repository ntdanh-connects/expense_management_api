<?php

namespace App\Jobs;

use App\Models\Budget;
use App\Models\Transaction;
use App\Services\ExchangeRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RecalculateUserCurrenciesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    protected $newCurrency;

    /**
     * Create a new job instance.
     */
    public function __construct(string $userId, string $newCurrency)
    {
        $this->userId = $userId;
        $this->newCurrency = strtoupper(trim($newCurrency));
    }

    /**
     * Execute the job.
     */
    public function handle(ExchangeRateService $exchangeRateService): void
    {
        // 1. Quy đổi lại toàn bộ cột amount_in_user_currency của tất cả giao dịch thuộc user
        $transactions = Transaction::where('user_id', $this->userId)->get();

        foreach ($transactions as $tx) {
            $txCurrency = strtoupper(trim($tx->currency_code));
            $amount = (float) $tx->amount;

            if ($txCurrency === $this->newCurrency) {
                $amountInUserCurrency = $amount;
            } else {
                $rate = $exchangeRateService->getRate($txCurrency, $this->newCurrency);
                $amountInUserCurrency = (float) bcmul((string)$amount, sprintf('%.6f', $rate), 4);
            }

            $tx->update([
                'amount_in_user_currency' => round($amountInUserCurrency, 2)
            ]);
        }

        // 2. Tính toán lại toàn bộ ngân sách hiện có của user
        $budgets = Budget::where('user_id', $this->userId)->get();

        foreach ($budgets as $budget) {
            $categoryId = $budget->category_id;
            $month = $budget->month;
            $year = $budget->year;

            DB::transaction(function () use ($budget, $categoryId, $month, $year) {
                // Lock dòng ngân sách
                $lockedBudget = Budget::where('id', $budget->id)->lockForUpdate()->first();
                if (!$lockedBudget) return;

                // Lấy tất cả danh mục con (nếu có)
                $categoryIds = [];
                if ($categoryId !== null) {
                    $categoryIds[] = $categoryId;
                    $children = DB::table('categories')
                        ->where('parent_id', $categoryId)
                        ->whereNull('deleted_at')
                        ->pluck('id')
                        ->toArray();
                    $categoryIds = array_merge($categoryIds, $children);
                }

                [$startDate, $endDate] = \App\Helpers\FinancialHelper::getFinancialRangeForMonth($this->userId, $month, $year);

                // Query tính tổng chi tiêu bằng tiền tệ hiển thị mới
                $query = DB::table('transactions')
                    ->where('user_id', $this->userId)
                    ->where('type', 'expense')
                    ->where('status', 'completed')
                    ->whereNull('deleted_at')
                    ->where(function ($q) {
                        $q->where(function ($sub) {
                            $sub->where('transactions.source_type', '!=', 'transfer')
                                ->orWhereNull('transactions.source_type');
                        })
                        ->orWhere(function ($sub) {
                            $sub->where('transactions.source_type', '=', 'transfer')
                                ->where(function ($inner) {
                                    $inner->whereNull('transactions.source_id')
                                        ->orWhereNotExists(function ($existsQuery) {
                                            $existsQuery->select(DB::raw(1))
                                                ->from('wallet_transfers as wt')
                                                ->join('wallets as fw', 'wt.from_wallet_id', '=', 'fw.id')
                                                ->join('wallets as tw', 'wt.to_wallet_id', '=', 'tw.id')
                                                ->whereColumn('wt.id', 'transactions.source_id')
                                                ->whereColumn('fw.user_id', 'tw.user_id');
                                        });
                                    });
                            });
                    })
                    ->whereBetween('transaction_date', [$startDate, $endDate]);

                if (!empty($categoryIds)) {
                    $query->whereIn('category_id', $categoryIds);
                }

                $sum = $query->sum('amount_in_user_currency');
                $usedAmount = (float) $sum;

                // Cập nhật lại số tiền đã tiêu của ngân sách
                DB::table('budget_usages')->updateOrInsert(
                    ['budget_id' => $budget->id],
                    ['used_amount' => $usedAmount, 'updated_at' => now()]
                );
            });
        }
    }
}
