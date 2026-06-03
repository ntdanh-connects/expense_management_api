<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class TransactionRepository extends BaseRepository implements TransactionRepositoryInterface
{
    public function getModel()
    {
        return Transaction::class;
    }

    public function getFilteredTransactions(string $userId, array $filters, string $sortBy, string $sortOrder, int $perPage)
    {
        $query = $this->model->newQuery()
            ->with(['category', 'wallet', 'attachments'])
            ->where('transactions.user_id', $userId);

        // 1. Tìm kiếm theo từ khóa (tiêu đề, ghi chú)
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('transactions.title', 'like', $search)
                  ->orWhere('transactions.notes', 'like', $search);
            });
        }

        // 2. Lọc theo khoảng thời gian (start_date, end_date)
        if (!empty($filters['start_date'])) {
            $query->where('transactions.transaction_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('transactions.transaction_date', '<=', $filters['end_date']);
        }

        // 3. Lọc theo danh mục (category_id)
        if (!empty($filters['category_id'])) {
            if (is_array($filters['category_id'])) {
                $query->whereIn('transactions.category_id', $filters['category_id']);
            } else {
                $query->where('transactions.category_id', $filters['category_id']);
            }
        }

        // 4. Lọc theo loại (income, expense, transfer)
        if (!empty($filters['type'])) {
            $type = strtolower($filters['type']);
            if ($type === 'transfer') {
                $query->where('transactions.source_type', 'transfer');
            } elseif ($type === 'income' || $type === 'expense') {
                $query->where('transactions.type', $type)
                      ->where(function ($q) {
                          $q->where('transactions.source_type', '!=', 'transfer')
                            ->orWhereNull('transactions.source_type');
                      });
            }
        }

        // 5. Lọc theo khoảng số tiền
        if (isset($filters['min_amount'])) {
            $query->where('transactions.amount', '>=', $filters['min_amount']);
        }
        if (isset($filters['max_amount'])) {
            $query->where('transactions.amount', '<=', $filters['max_amount']);
        }

        // 6. Lọc theo ví (nếu truyền wallet_id)
        if (!empty($filters['wallet_id'])) {
            $query->where('transactions.wallet_id', $filters['wallet_id']);
        }

        // 7. Sắp xếp theo: ngày (date), số tiền (amount), danh mục (category)
        $allowedSorts = [
            'date' => 'transactions.transaction_date',
            'amount' => 'transactions.amount',
            'category' => 'categories.name'
        ];

        $sortColumn = $allowedSorts[$sortBy] ?? 'transactions.transaction_date';
        $order = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'category') {
            // Join với bảng categories để sắp xếp theo tên
            $query->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
                  ->select('transactions.*') // Đảm bảo không lấy đè cột id của category
                  ->orderBy('categories.name', $order);
        } else {
            $query->orderBy($sortColumn, $order);
        }

        // Luôn sắp xếp phụ theo id hoặc created_at để tránh trùng lặp khi phân trang
        $query->orderBy('transactions.created_at', 'desc');

        return $query->paginate($perPage);
    }
}
