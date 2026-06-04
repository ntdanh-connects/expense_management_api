<?php

namespace App\Services;

use App\Repositories\Contracts\BudgetRepositoryInterface;
use App\Models\Budget;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    protected $budgetRepository;

    public function __construct(BudgetRepositoryInterface $budgetRepository)
    {
        $this->budgetRepository = $budgetRepository;
    }

    /**
     * Lấy tất cả ngân sách kèm số tiền đã sử dụng của user theo tháng/năm
     */
    public function getAllUserBudgets(string $userId, int $month, int $year)
    {
        return $this->budgetRepository->getBudgetsWithUsage($userId, $month, $year);
    }

    /**
     * Tạo mới hoặc cập nhật ngân sách
     */
    public function createOrUpdateBudget(string $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            $categoryId = $data['category_id'] ?? null;
            $month = (int) $data['month'];
            $year = (int) $data['year'];
            $limitAmount = (float) $data['limit_amount'];

            // Kiểm tra tính hợp lệ của danh mục nếu có truyền
            if ($categoryId) {
                $category = DB::table('categories')
                    ->where('id', $categoryId)
                    ->where(function ($q) use ($userId) {
                        $q->where('user_id', $userId)
                          ->orWhere('is_default', true);
                    })
                    ->whereNull('deleted_at')
                    ->first();

                if (!$category) {
                    throw new \Exception(__('messages.category_not_found_or_unauthorized'));
                }
            }

            // Tìm xem ngân sách cho danh mục/tháng/năm này đã tồn tại chưa
            $budget = $this->budgetRepository->findExistingBudget($userId, $categoryId, $month, $year);

            if ($budget) {
                // Đã tồn tại -> Cập nhật hạn mức
                $budget->update([
                    'limit_amount' => $limitAmount
                ]);
            } else {
                // Chưa tồn tại -> Tạo mới cứng
                $budget = $this->budgetRepository->create([
                    'user_id' => $userId,
                    'category_id' => $categoryId,
                    'limit_amount' => $limitAmount,
                    'month' => $month,
                    'year' => $year
                ]);
            }

            // Tự động tính toán số dư tiêu dùng ban đầu
            $this->recalculateSingleBudget($budget);

            return $budget->load('category', 'usage');
        });
    }

    /**
     * Xóa ngân sách
     */
    public function deleteBudget(string $budgetId, string $userId)
    {
        $budget = $this->budgetRepository->find($budgetId);

        if (!$budget || $budget->user_id !== $userId) {
            throw new \Exception(__('messages.budget_not_found_or_unauthorized'));
        }

        // Eloquent deleting event sẽ tự động dọn sạch budget_usages và budget_alerts
        return $budget->delete();
    }

    /**
     * Sao chép toàn bộ ngân sách tháng trước sang tháng mới
     */
    public function copyBudgets(string $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            $fromMonth = (int) $data['from_month'];
            $fromYear = (int) $data['from_year'];
            $toMonth = (int) $data['to_month'];
            $toYear = (int) $data['to_year'];

            if ($fromMonth === $toMonth && $fromYear === $toYear) {
                throw new \Exception(__('messages.source_target_months_same'));
            }

            // Lấy danh sách ngân sách nguồn
            $sourceBudgets = Budget::where('user_id', $userId)
                ->where('month', $fromMonth)
                ->where('year', $fromYear)
                ->get();

            if ($sourceBudgets->isEmpty()) {
                throw new \Exception(__('messages.no_source_budgets_found'));
            }

            $copiedBudgets = [];

            foreach ($sourceBudgets as $src) {
                // Kiểm tra xem tại tháng đích đã có ngân sách cho danh mục này chưa
                $exists = $this->budgetRepository->findExistingBudget($userId, $src->category_id, $toMonth, $toYear);

                if (!$exists) {
                    $newBudget = $this->budgetRepository->create([
                        'user_id' => $userId,
                        'category_id' => $src->category_id,
                        'limit_amount' => $src->limit_amount,
                        'month' => $toMonth,
                        'year' => $toYear
                    ]);

                    // Tính toán lại số dư đã tiêu dùng cho ngân sách mới tại tháng đích
                    $this->recalculateSingleBudget($newBudget);
                    $copiedBudgets[] = $newBudget;
                }
            }

            return $copiedBudgets;
        });
    }

    /**
     * Hàm phụ trợ tính toán số dư tiêu dùng của một ngân sách đơn lẻ
     */
    public function recalculateSingleBudget(Budget $budget): void
    {
        $userId = $budget->user_id;
        $categoryId = $budget->category_id;
        $month = $budget->month;
        $year = $budget->year;

        DB::transaction(function () use ($budget, $userId, $categoryId, $month, $year) {
            // Xác định các danh mục liên quan (bao gồm danh mục con nếu có)
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

            // Query tính tổng số tiền của các giao dịch chi tiêu tương ứng
            $query = DB::table('transactions')
                ->where('user_id', $userId)
                ->where('type', 'expense')
                ->where('status', 'completed')
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->where('source_type', '!=', 'transfer')
                      ->orWhereNull('source_type');
                })
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month);

            if (!empty($categoryIds)) {
                $query->whereIn('category_id', $categoryIds);
            }

            $sum = $query->sum('amount_in_user_currency');
            $usedAmount = (float) $sum;

            // Cập nhật/Chèn vào bảng budget_usages
            DB::table('budget_usages')->updateOrInsert(
                ['budget_id' => $budget->id],
                ['used_amount' => $usedAmount, 'updated_at' => now()]
            );
        });
    }
}
