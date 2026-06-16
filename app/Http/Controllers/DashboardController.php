<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use App\Services\BudgetService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    protected $walletService;
    protected $budgetService;
    protected $transactionService;

    public function __construct(
        WalletService $walletService,
        BudgetService $budgetService,
        TransactionService $transactionService
    ) {
        $this->walletService = $walletService;
        $this->budgetService = $budgetService;
        $this->transactionService = $transactionService;
    }

    /**
     * GET /api/dashboard/summary
     * API Aggregation: Gom tất cả dữ liệu cần thiết cho màn hình chính (wallets, budgets, recent_transactions, unread_notifications_count, thu/chi tháng hiện tại)
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            // Lấy version cache hiện tại của user để làm cache key cho các phần dữ liệu thay đổi theo giao dịch
            $version = Cache::get("user_{$userId}_report_version", 1);

            // 1. Xác định timezone và khoảng thời gian tháng hiện tại của người dùng theo chu kỳ tài chính
            [$startDate, $endDate] = \App\Helpers\FinancialHelper::getFinancialRangeForDate($userId, \Illuminate\Support\Carbon::now());
            [$month, $year] = \App\Helpers\FinancialHelper::getFinancialMonthAndYearForDate($userId, \Illuminate\Support\Carbon::now());

            // 2. Lấy danh sách ví và số dư (Không cache hoặc cache rất ngắn để hiển thị cấu hình ví/số dư thay đổi tức thời)
            $wallets = $this->walletService->getAllUserWallets($userId);
            // Đảm bảo wallets luôn là mảng phẳng khi trả về
            $walletsArray = $wallets instanceof \Illuminate\Support\Collection 
                ? $wallets->values()->toArray() 
                : (is_array($wallets) ? array_values($wallets) : []);
            // Lọc phòng vệ loại bỏ ví thiếu id
            $walletsArray = array_values(array_filter($walletsArray, function ($w) {
                return is_array($w) && isset($w['id']) && !is_null($w['id']);
            }));

            // 3. Lấy tình hình ngân sách tháng hiện tại (Cache theo version + month + year)
            // Đổi cache key sang v3 để vô hiệu hoá triệt để cache cũ trên Production
            $budgetsCacheKey = "user_{$userId}_budgets_v3_{$version}_{$month}_{$year}";
            $budgetsData = Cache::remember($budgetsCacheKey, 3600, function () use ($userId, $month, $year) {
                $budgets = $this->budgetService->getAllUserBudgets($userId, $month, $year);
                return $budgets instanceof \Illuminate\Support\Collection ? $budgets->values()->toArray() : $budgets;
            });
            // Đảm bảo budgetsArray luôn là mảng phẳng khi trả về (an toàn trước cache cũ)
            $budgetsArray = $budgetsData instanceof \Illuminate\Support\Collection 
                ? $budgetsData->values()->toArray() 
                : (is_array($budgetsData) ? array_values($budgetsData) : []);
            // Lọc phòng vệ loại bỏ ngân sách thiếu id
            $budgetsArray = array_values(array_filter($budgetsArray, function ($b) {
                return is_array($b) && isset($b['id']) && !is_null($b['id']);
            }));

            // 4. Lấy 10 giao dịch gần đây nhất (Cache theo version)
            // Đổi cache key sang v3 để vô hiệu hoá triệt để cache cũ trên Production
            $txCacheKey = "user_{$userId}_recent_txs_v3_{$version}";
            $recentTransactionsData = Cache::remember($txCacheKey, 3600, function () use ($userId) {
                $recentTransactionsPaginator = $this->transactionService->getTransactions($userId, [], 'date', 'desc', 10);
                $items = $recentTransactionsPaginator->items();
                return collect($items)->values()->toArray();
            });
            // Đảm bảo recentTransactionsArray luôn là mảng phẳng khi trả về
            $recentTransactionsArray = $recentTransactionsData instanceof \Illuminate\Support\Collection 
                ? $recentTransactionsData->values()->toArray() 
                : (is_array($recentTransactionsData) ? array_values($recentTransactionsData) : []);
            // Lọc phòng vệ loại bỏ giao dịch thiếu id
            $recentTransactionsArray = array_values(array_filter($recentTransactionsArray, function ($t) {
                return is_array($t) && isset($t['id']) && !is_null($t['id']);
            }));

            // 5. Đếm số lượng thông báo chưa đọc (Không cache để hiển thị badge số lượng thông báo realtime khi có push notification)
            $unreadNotificationsCount = DB::table('notifications')
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->count();

            // Đổi cache key sang v3 để tránh xung đột với cache cũ (thêm $version để tự động làm mới khi có giao dịch)
            $summaryCacheKey = "user_{$userId}_dashboard_sum_v3_{$version}_{$month}_{$year}";
            $summary = Cache::remember($summaryCacheKey, 3600, function () use ($userId, $startDate, $endDate) {
                $query = DB::table('transactions')
                    ->where('user_id', $userId)
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
                    ->whereNull('deleted_at');

                $summaryResult = $query->select(
                    DB::raw("SUM(CASE WHEN type = 'income' THEN amount_in_user_currency ELSE 0 END) as total_income"),
                    DB::raw("SUM(CASE WHEN type = 'expense' THEN amount_in_user_currency ELSE 0 END) as total_expense")
                )->first();

                $income = (float) ($summaryResult->total_income ?? 0);
                $expense = (float) ($summaryResult->total_expense ?? 0);
                $net = $income - $expense;

                return [
                    'income'  => $income,
                    'expense' => $expense,
                    'net'     => $net
                ];
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Lấy dữ liệu tổng hợp Dashboard thành công!',
                'data'    => [
                    'wallets'                     => $walletsArray,
                    'current_month_budgets'       => $budgetsArray,
                    'recent_transactions'         => $recentTransactionsArray,
                    'unread_notifications_count'  => $unreadNotificationsCount,
                    'summary'                     => $summary
                ]
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
