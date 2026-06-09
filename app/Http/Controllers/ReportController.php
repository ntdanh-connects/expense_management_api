<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    /**
     * GET /api/reports/summary
     * Lấy tóm tắt thu chi (income, expense, net) trong khoảng thời gian
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
        $startDate = Carbon::parse($request->query('start_date'))->startOfDay();
        $endDate = Carbon::parse($request->query('end_date'))->endOfDay();
        $walletId = $request->query('wallet_id');

        if ($walletId) {
            // Nếu lọc theo ví, bắt buộc phải truy vấn từ bảng transactions vì bảng statistics không lưu theo ví
            $result = DB::table('transactions')
                ->select(
                    DB::raw("SUM(CASE WHEN type = 'income' THEN amount_in_user_currency ELSE 0 END) as total_income"),
                    DB::raw("SUM(CASE WHEN type = 'expense' THEN amount_in_user_currency ELSE 0 END) as total_expense")
                )
                ->where('user_id', $userId)
                ->where('wallet_id', $walletId)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->whereNull('deleted_at')
                ->first();
        } else {
            // Nếu không lọc theo ví, truy vấn từ daily_statistics cho nhanh
            $result = DB::table('daily_statistics')
                ->select(
                    DB::raw("SUM(income) as total_income"),
                    DB::raw("SUM(expense) as total_expense")
                )
                ->where('user_id', $userId)
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->first();
        }

        $income = (float) ($result->total_income ?? 0);
        $expense = (float) ($result->total_expense ?? 0);
        $net = $income - $expense;

        return response()->json([
            'status' => 'success',
            'data' => [
                'income' => $income,
                'expense' => $expense,
                'net' => $net
            ]
        ]);
    }

    /**
     * GET /api/reports/category
     * Báo cáo cơ cấu chi tiêu/thu nhập theo danh mục
     */
    public function category(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer',
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
        $month = (int) $request->query('month');
        $year = (int) $request->query('year');
        $type = $request->query('type') ?? 'expense'; // Mặc định là chi tiêu

        // Lấy tổng số tiền của loại giao dịch đó trong tháng/năm để tính tỷ lệ %
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

        // Lấy chi tiết thống kê từng hạng mục
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

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_amount' => $totalAmount,
                'categories' => $data
            ]
        ]);
    }

    /**
     * GET /api/reports/trends
     * Xu hướng thu chi (income và expense theo thời gian)
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
        $startDate = Carbon::parse($request->query('start_date'));
        $endDate = Carbon::parse($request->query('end_date'));
        $groupBy = $request->query('group_by') ?? 'day';

        $data = [];

        if ($groupBy === 'day') {
            $stats = DB::table('daily_statistics')
                ->where('user_id', $userId)
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->orderBy('date', 'asc')
                ->get();

            $data = $stats->map(function ($item) {
                return [
                    'label' => Carbon::parse($item->date)->format('d/m'),
                    'date' => $item->date,
                    'income' => (float) $item->income,
                    'expense' => (float) $item->expense
                ];
            });
        } else {
            // Group theo tháng
            $stats = DB::table('monthly_statistics')
                ->where('user_id', $userId)
                ->where(function($query) use ($startDate, $endDate) {
                    // Lọc theo khoảng năm/tháng
                    $startVal = $startDate->year * 12 + $startDate->month;
                    $endVal = $endDate->year * 12 + $endDate->month;
                    $query->whereRaw("year * 12 + month BETWEEN ? AND ?", [$startVal, $endVal]);
                })
                ->orderBy('year', 'asc')
                ->orderBy('month', 'asc')
                ->get();

            $data = $stats->map(function ($item) {
                return [
                    'label' => sprintf('%02d/%d', $item->month, $item->year),
                    'month' => (int) $item->month,
                    'year' => (int) $item->year,
                    'income' => (float) $item->income,
                    'expense' => (float) $item->expense
                ];
            });
        }

        return response()->json([
            'status' => 'success',
            'data' => $data
        ]);
    }
}
