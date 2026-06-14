<?php

namespace App\Listeners;

use App\Events\TransactionSaved;
use App\Models\Budget;
use App\Models\User;
use App\Notifications\BudgetWarningNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateBudgetUsage implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(TransactionSaved $event): void
    {
        $transaction = $event->transaction;
        $userId = $transaction->user_id;

        // 1. Xác định danh sách các cặp (category_id, month, year) cần tính toán lại
        $targets = [];

        // Cặp hiện tại từ giao dịch
        $date = Carbon::parse($transaction->transaction_date);
        $targets[] = [
            'category_id' => $transaction->category_id,
            'month' => $date->month,
            'year' => $date->year
        ];
        // Thêm ngân sách tổng (category_id = null)
        $targets[] = [
            'category_id' => null,
            'month' => $date->month,
            'year' => $date->year
        ];

        // Cặp cũ (nếu có update và có thay đổi thông tin quan trọng)
        if ($event->oldData) {
            $oldDate = Carbon::parse($event->oldData['transaction_date'] ?? $transaction->transaction_date);
            $oldCategoryId = array_key_exists('category_id', $event->oldData) ? $event->oldData['category_id'] : $transaction->category_id;
            
            $targets[] = [
                'category_id' => $oldCategoryId,
                'month' => $oldDate->month,
                'year' => $oldDate->year
            ];
            // Thêm ngân sách tổng cũ
            $targets[] = [
                'category_id' => null,
                'month' => $oldDate->month,
                'year' => $oldDate->year
            ];
        }

        // Loại bỏ trùng lặp
        $uniqueTargets = [];
        foreach ($targets as $t) {
            $key = ($t['category_id'] ?? 'null') . '_' . $t['month'] . '_' . $t['year'];
            $uniqueTargets[$key] = $t;
        }

        // 2. Chạy tính toán lại cho từng cặp ngân sách
        foreach ($uniqueTargets as $target) {
            $categoryId = $target['category_id'];
            $month = $target['month'];
            $year = $target['year'];

            // Tìm ngân sách của người dùng tương ứng
            $budgetQuery = Budget::where('user_id', $userId)
                ->where('month', $month)
                ->where('year', $year);

            if ($categoryId === null) {
                $budgetQuery->whereNull('category_id');
            } else {
                $budgetQuery->where('category_id', $categoryId);
            }

            $budget = $budgetQuery->first();

            if (!$budget) {
                continue;
            }

            // Lock & thực hiện tính toán số dư đã dùng
            DB::transaction(function () use ($budget, $userId, $categoryId, $month, $year) {
                // Lock dòng ngân sách để tránh ghi đè đồng thời
                $lockedBudget = Budget::where('id', $budget->id)->lockForUpdate()->first();
                if (!$lockedBudget) return;

                // Xác định tất cả các category_id cần tính (bao gồm cả các category con nếu categoryId là category cha)
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

                // Query sum tất cả giao dịch chi tiêu hoàn tất của user
                $query = DB::table('transactions')
                    ->where('user_id', $userId)
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
                    ->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month);

                if (!empty($categoryIds)) {
                    $query->whereIn('category_id', $categoryIds);
                }

                $sum = $query->sum('amount_in_user_currency');
                $usedAmount = (float) $sum;

                // Cập nhật số dư trong bảng budget_usages
                DB::table('budget_usages')->updateOrInsert(
                    ['budget_id' => $budget->id],
                    ['used_amount' => $usedAmount, 'updated_at' => now()]
                );

                // 3. Kiểm tra các ngưỡng cảnh báo ngân sách
                $limitAmount = (float) $budget->limit_amount;
                if ($limitAmount > 0) {
                    $user = User::with(['profile', 'preference'])->find($userId);
                    if (!$user) return;

                    // Ngưỡng 100%
                    if ($usedAmount >= $limitAmount) {
                        $alertExists = DB::table('budget_alerts')
                            ->where('budget_id', $budget->id)
                            ->where('threshold_percent', 100)
                            ->exists();

                        if (!$alertExists) {
                            DB::table('budget_alerts')->insert([
                                'id' => (string) \Illuminate\Support\Str::uuid7(),
                                'budget_id' => $budget->id,
                                'threshold_percent' => 100,
                                'triggered_at' => now()
                            ]);

                            $user->notify(new BudgetWarningNotification($budget, 100, $usedAmount));
                        }
                    } 
                    // Ngưỡng 80%
                    elseif ($usedAmount >= $limitAmount * 0.8) {
                        $alertExists = DB::table('budget_alerts')
                            ->where('budget_id', $budget->id)
                            ->where('threshold_percent', 80)
                            ->exists();

                        if (!$alertExists) {
                            DB::table('budget_alerts')->insert([
                                'id' => (string) \Illuminate\Support\Str::uuid7(),
                                'budget_id' => $budget->id,
                                'threshold_percent' => 80,
                                'triggered_at' => now()
                            ]);

                            $user->notify(new BudgetWarningNotification($budget, 80, $usedAmount));
                        }
                    }
                }
            });
        }
    }
}
