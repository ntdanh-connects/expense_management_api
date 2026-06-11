<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ReportController extends Controller
{
    /**
     * GET /api/reports/summary
     * Lấy tóm tắt thu chi (income, expense, net) trong khoảng thời gian, loại bỏ chuyển khoản nội bộ
     */
    public function summary(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'wallet_id' => 'nullable|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dữ liệu đầu vào không hợp lệ.',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = $request->attributes->get('user_id');
        $userTimezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
        $startDate = Carbon::parse($request->query('start_date'), $userTimezone)->startOfDay()->setTimezone('UTC');
        $endDate = Carbon::parse($request->query('end_date'), $userTimezone)->endOfDay()->setTimezone('UTC');
        $walletId = $request->query('wallet_id');

        $version = Cache::get("user_{$userId}_report_version", 1);
        $cacheKey = "user_{$userId}_report_{$version}_summary_" . md5(json_encode($request->all()));

        $data = Cache::remember($cacheKey, 600, function() use ($userId, $startDate, $endDate, $walletId) {
            $query = DB::table('transactions')
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->where('source_type', '!=', 'transfer')
                      ->orWhereNull('source_type');
                })
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->whereNull('deleted_at');

            if ($walletId) {
                $query->where('wallet_id', $walletId);
            }

            $result = $query->select(
                DB::raw("SUM(CASE WHEN type = 'income' THEN amount_in_user_currency ELSE 0 END) as total_income"),
                DB::raw("SUM(CASE WHEN type = 'expense' THEN amount_in_user_currency ELSE 0 END) as total_expense")
            )->first();

            $income = (float) ($result->total_income ?? 0);
            $expense = (float) ($result->total_expense ?? 0);
            $net = $income - $expense;

            return [
                'income' => $income,
                'expense' => $expense,
                'net' => $net
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Lấy tóm tắt báo cáo thành công',
            'data' => $data
        ]);
    }

    /**
     * GET /api/reports/categories
     * Báo cáo cơ cấu chi tiêu/thu nhập theo danh mục, loại bỏ chuyển khoản nội bộ
     */
    public function category(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required_without:start_date,end_date|nullable|integer|between:1,12',
            'year' => 'required_without:start_date,end_date|nullable|integer',
            'start_date' => 'required_without:month,year|nullable|date',
            'end_date' => 'required_with:start_date|nullable|date|after_or_equal:start_date',
            'type' => 'nullable|string|in:income,expense'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dữ liệu đầu vào không hợp lệ.',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = $request->attributes->get('user_id');
        $type = $request->query('type') ?? 'expense';

        $version = Cache::get("user_{$userId}_report_version", 1);
        $cacheKey = "user_{$userId}_report_{$version}_category_" . md5(json_encode($request->all()));

        $response = Cache::remember($cacheKey, 600, function() use ($request, $userId, $type) {
            if ($request->has('start_date') && $request->has('end_date')) {
                $userTimezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
                $startDate = Carbon::parse($request->query('start_date'), $userTimezone)->startOfDay()->setTimezone('UTC');
                $endDate = Carbon::parse($request->query('end_date'), $userTimezone)->endOfDay()->setTimezone('UTC');

                // Lấy tổng số tiền của loại giao dịch đó trong khoảng thời gian để tính tỷ lệ %
                $totalAmountResult = DB::table('transactions')
                    ->join('categories', 'transactions.category_id', '=', 'categories.id')
                    ->where('transactions.user_id', $userId)
                    ->whereNull('transactions.deleted_at')
                    ->whereNull('categories.deleted_at')
                    ->where('categories.type', $type)
                    ->where(function ($q) {
                        $q->where('transactions.source_type', '!=', 'transfer')
                          ->orWhereNull('transactions.source_type');
                    })
                    ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
                    ->select(DB::raw("SUM(transactions.amount_in_user_currency) as total"))
                    ->first();

                $totalAmount = (float) ($totalAmountResult->total ?? 0);

                // Lấy chi tiết thống kê từng hạng mục từ bảng transactions
                $categoriesStats = DB::table('transactions')
                    ->join('categories', 'transactions.category_id', '=', 'categories.id')
                    ->leftJoin('categories as parent', 'categories.parent_id', '=', 'parent.id')
                    ->where('transactions.user_id', $userId)
                    ->whereNull('transactions.deleted_at')
                    ->whereNull('categories.deleted_at')
                    ->where('categories.type', $type)
                    ->where(function ($q) {
                        $q->where('transactions.source_type', '!=', 'transfer')
                          ->orWhereNull('transactions.source_type');
                    })
                    ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
                    ->select(
                        'categories.id as category_id',
                        'categories.name as category_name',
                        'categories.color as category_color',
                        'categories.icon as category_icon',
                        'categories.parent_id',
                        'parent.name as parent_name',
                        DB::raw("SUM(transactions.amount_in_user_currency) as amount")
                    )
                    ->groupBy(
                        'categories.id',
                        'categories.name',
                        'categories.color',
                        'categories.icon',
                        'categories.parent_id',
                        'parent.name'
                    )
                    ->orderBy('amount', 'desc')
                    ->get();
            } else {
                $month = (int) $request->query('month');
                $year = (int) $request->query('year');

                $totalAmountResult = DB::table('category_statistics')
                    ->join('categories', 'category_statistics.category_id', '=', 'categories.id')
                    ->where('category_statistics.user_id', $userId)
                    ->where('category_statistics.month', $month)
                    ->where('category_statistics.year', $year)
                    ->where('categories.type', $type)
                    ->whereNull('categories.deleted_at')
                    ->select(DB::raw("SUM(category_statistics.total_amount) as total"))
                    ->first();

                $totalAmount = (float) ($totalAmountResult->total ?? 0);

                $categoriesStats = DB::table('category_statistics')
                    ->join('categories', 'category_statistics.category_id', '=', 'categories.id')
                    ->leftJoin('categories as parent', 'categories.parent_id', '=', 'parent.id')
                    ->where('category_statistics.user_id', $userId)
                    ->where('category_statistics.month', $month)
                    ->where('category_statistics.year', $year)
                    ->where('categories.type', $type)
                    ->whereNull('categories.deleted_at')
                    ->select(
                        'categories.id as category_id',
                        'categories.name as category_name',
                        'categories.color as category_color',
                        'categories.icon as category_icon',
                        'categories.parent_id',
                        'parent.name as parent_name',
                        'category_statistics.total_amount as amount'
                    )
                    ->orderBy('amount', 'desc')
                    ->get();
            }

            $data = $categoriesStats->map(function ($item) use ($totalAmount) {
                $amount = (float) $item->amount;
                $percentage = $totalAmount > 0 ? round(($amount / $totalAmount) * 100, 2) : 0;
                return [
                    'category_id' => $item->category_id,
                    'category_name' => $item->category_name,
                    'category_color' => $item->category_color,
                    'category_icon' => $item->category_icon,
                    'parent_id' => $item->parent_id,
                    'parent_name' => $item->parent_name,
                    'amount' => $amount,
                    'percentage' => $percentage
                ];
            });

            return [
                'total_amount' => $totalAmount,
                'categories' => $data->values()->all()
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Lấy phân bổ chi tiêu thành công',
            'data' => $response
        ]);
    }

    /**
     * GET /api/reports/trends
     * Xu hướng thu chi (income và expense theo thời gian), loại bỏ chuyển khoản nội bộ
     */
    public function trends(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'group_by' => 'nullable|string|in:day,month'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dữ liệu đầu vào không hợp lệ.',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = $request->attributes->get('user_id');
        $userTimezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
        $startDate = Carbon::parse($request->query('start_date'), $userTimezone);
        $endDate = Carbon::parse($request->query('end_date'), $userTimezone);
        $groupBy = $request->query('group_by') ?? 'day';

        $version = Cache::get("user_{$userId}_report_version", 1);
        $cacheKey = "user_{$userId}_report_{$version}_trends_" . md5(json_encode($request->all()));

        $data = Cache::remember($cacheKey, 600, function() use ($userId, $startDate, $endDate, $groupBy, $userTimezone) {
            $dbStartDate = $startDate->copy()->startOfDay()->setTimezone('UTC');
            $dbEndDate = $endDate->copy()->endOfDay()->setTimezone('UTC');

            $transactions = DB::table('transactions')
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->where('source_type', '!=', 'transfer')
                      ->orWhereNull('source_type');
                })
                ->whereBetween('transaction_date', [$dbStartDate, $dbEndDate])
                ->whereNull('deleted_at')
                ->orderBy('transaction_date', 'asc')
                ->get();

            if ($groupBy === 'day') {
                $grouped = $transactions->groupBy(function($item) use ($userTimezone) {
                    return Carbon::parse($item->transaction_date)->setTimezone($userTimezone)->toDateString();
                });

                return $grouped->map(function($items, $dateStr) use ($userTimezone) {
                    $income = $items->where('type', 'income')->sum('amount_in_user_currency');
                    $expense = $items->where('type', 'expense')->sum('amount_in_user_currency');
                    return [
                        'label' => Carbon::parse($dateStr, $userTimezone)->format('d/m'),
                        'date' => $dateStr,
                        'income' => (float) $income,
                        'expense' => (float) $expense
                    ];
                })->values()->toArray();
            } else {
                $grouped = $transactions->groupBy(function($item) use ($userTimezone) {
                    return Carbon::parse($item->transaction_date)->setTimezone($userTimezone)->format('Y-m');
                });

                $result = [];
                $current = $startDate->copy()->startOfMonth();
                $end = $endDate->copy()->startOfMonth();

                while ($current->lte($end)) {
                    $monthStr = $current->format('Y-m');
                    $items = $grouped->get($monthStr, collect([]));

                    $income = $items->where('type', 'income')->sum('amount_in_user_currency');
                    $expense = $items->where('type', 'expense')->sum('amount_in_user_currency');

                    $result[] = [
                        'label' => $current->format('m/Y'),
                        'month' => $current->month,
                        'year' => $current->year,
                        'income' => (float) $income,
                        'expense' => (float) $expense
                    ];

                    $current->addMonth();
                }

                return $result;
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Lấy xu hướng chi tiêu thành công',
            'data' => $data
        ]);
    }
}
