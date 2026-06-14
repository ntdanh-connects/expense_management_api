<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklySummaryNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendWeeklySummary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:weekly-summary';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gửi email tóm tắt chi tiêu hàng tuần vào tối Chủ Nhật';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Bắt đầu gửi tóm tắt chi tiêu hàng tuần...');

        $users = User::where('status', 'active')->get();
        $sentCount = 0;

        foreach ($users as $user) {
            // Chỉ gửi cho user có email hợp lệ
            if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Kiểm tra cấu hình preferences của user
            $preference = DB::table('notification_preferences')
                ->where('user_id', $user->user_id)
                ->first();

            // Nếu user tắt weekly summary thì bỏ qua
            if ($preference && isset($preference->weekly_summary_enabled) && !$preference->weekly_summary_enabled) {
                continue;
            }

            // Lấy timezone của user
            $timezone = DB::table('user_preferences')
                ->where('user_id', $user->user_id)
                ->value('timezone') ?? 'Asia/Ho_Chi_Minh';

            // Tuần qua từ thứ 2 đến chủ nhật (hôm nay)
            $now = Carbon::now($timezone);
            $startDate = $now->copy()->startOfWeek(); // Thứ 2 lúc 00:00:00
            $endDate = $now->copy()->endOfDay(); // Chủ nhật lúc 23:59:59

            // Lấy tổng thu nhập & chi tiêu trong tuần qua (bỏ qua chuyển tiền nội bộ)
            $stats = DB::table('transactions')
                ->select(
                    DB::raw("SUM(CASE WHEN type = 'income' THEN amount_in_user_currency ELSE 0 END) as total_income"),
                    DB::raw("SUM(CASE WHEN type = 'expense' THEN amount_in_user_currency ELSE 0 END) as total_expense")
                )
                ->where('user_id', $user->user_id)
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
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->whereNull('deleted_at')
                ->first();

            $income = (float) ($stats->total_income ?? 0);
            $expense = (float) ($stats->total_expense ?? 0);

            // Báo cáo danh mục
            $totalAmountResult = DB::table('transactions')
                ->join('categories', 'transactions.category_id', '=', 'categories.id')
                ->where('transactions.user_id', $user->user_id)
                ->whereNull('transactions.deleted_at')
                ->whereNull('categories.deleted_at')
                ->where('categories.type', 'expense')
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
                ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
                ->select(DB::raw("SUM(transactions.amount_in_user_currency) as total"))
                ->first();

            $totalExpenseAmount = (float) ($totalAmountResult->total ?? 0);

            $categoriesStats = DB::table('transactions')
                ->join('categories', 'transactions.category_id', '=', 'categories.id')
                ->where('transactions.user_id', $user->user_id)
                ->whereNull('transactions.deleted_at')
                ->whereNull('categories.deleted_at')
                ->where('categories.type', 'expense')
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
                ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
                ->select(
                    'categories.name as category_name',
                    DB::raw("SUM(transactions.amount_in_user_currency) as amount")
                )
                ->groupBy('categories.name')
                ->orderBy('amount', 'desc')
                ->limit(3)
                ->get();

            $breakdown = $categoriesStats->map(function ($item) use ($totalExpenseAmount) {
                $amount = (float) $item->amount;
                $percentage = $totalExpenseAmount > 0 ? round(($amount / $totalExpenseAmount) * 100, 1) : 0;
                return [
                    'category_name' => $item->category_name,
                    'amount' => $amount,
                    'percentage' => $percentage
                ];
            })->toArray();

            // Nếu user có giao dịch trong tuần (có thu hoặc có chi) thì mới gửi mail tóm tắt
            if ($income > 0 || $expense > 0) {
                $user->notify(new WeeklySummaryNotification(
                    $startDate->format('d/m/Y'),
                    $endDate->format('d/m/Y'),
                    $income,
                    $expense,
                    $breakdown
                ));
                $sentCount++;
            }
        }

        $this->info("Đã gửi {$sentCount} tóm tắt chi tiêu hàng tuần.");
        return Command::SUCCESS;
    }
}
