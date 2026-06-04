<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\BudgetRepositoryInterface;
use App\Models\Budget;
use Illuminate\Support\Facades\DB;

class BudgetRepository extends BaseRepository implements BudgetRepositoryInterface
{
    public function getModel()
    {
        return Budget::class;
    }

    /**
     * Lấy danh sách ngân sách của user kèm số tiền đã chi tiêu
     */
    public function getBudgetsWithUsage(string $userId, int $month, int $year)
    {
        return $this->model->newQuery()
            ->with(['category'])
            ->leftJoin('budget_usages', 'budgets.id', '=', 'budget_usages.budget_id')
            ->select([
                'budgets.id',
                'budgets.user_id',
                'budgets.category_id',
                'budgets.limit_amount',
                'budgets.month',
                'budgets.year',
                'budgets.created_at',
                'budgets.updated_at',
                DB::raw('COALESCE(budget_usages.used_amount, 0.00) as used_amount')
            ])
            ->where('budgets.user_id', $userId)
            ->where('budgets.month', $month)
            ->where('budgets.year', $year)
            ->orderBy('budgets.created_at', 'desc')
            ->get();
    }

    /**
     * Tìm ngân sách trùng lặp đã tồn tại
     */
    public function findExistingBudget(string $userId, ?string $categoryId, int $month, int $year)
    {
        $query = $this->model->newQuery()
            ->where('user_id', $userId)
            ->where('month', $month)
            ->where('year', $year);

        if ($categoryId === null) {
            $query->whereNull('category_id');
        } else {
            $query->where('category_id', $categoryId);
        }

        return $query->first();
    }
}
