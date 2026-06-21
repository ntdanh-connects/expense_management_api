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
            ->with(['category', 'wallet', 'attachments', 'payee'])
            ->where('transactions.user_id', $userId);

        // 1. Tìm kiếm theo từ khóa (tiêu đề, ghi chú)
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('transactions.title', 'ilike', $search)
                  ->orWhere('transactions.notes', 'ilike', $search);
            });
        }

        // 2. Lọc theo khoảng thời gian (start_date, end_date)
        if (!empty($filters['start_date']) || !empty($filters['end_date'])) {
            $userTimezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
            if (!empty($filters['start_date'])) {
                $startDate = \Carbon\Carbon::parse($filters['start_date'], $userTimezone)->startOfDay()->setTimezone('UTC');
                $query->where('transactions.transaction_date', '>=', $startDate);
            }
            if (!empty($filters['end_date'])) {
                $endDate = \Carbon\Carbon::parse($filters['end_date'], $userTimezone)->endOfDay()->setTimezone('UTC');
                $query->where('transactions.transaction_date', '<=', $endDate);
            }
        }

        // 3. Lọc theo danh mục (category_id)
        if (!empty($filters['category_id'])) {
            if (is_array($filters['category_id'])) {
                $query->whereIn('transactions.category_id', $filters['category_id']);
            } else {
                $catId = $filters['category_id'];
                $childIds = DB::table('categories')
                    ->where('parent_id', $catId)
                    ->whereNull('deleted_at')
                    ->pluck('id')
                    ->toArray();
                $allIds = array_merge([$catId], $childIds);
                $query->whereIn('transactions.category_id', $allIds);
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
                          $q->where(function ($sub) {
                              $sub->where('transactions.source_type', '!=', 'transfer')
                                  ->orWhereNull('transactions.source_type');
                          })
                          ->orWhere(function ($sub) {
                              $sub->where('transactions.source_type', '=', 'transfer')
                                  ->where(function ($inner) {
                                      $inner->whereNull('transactions.source_id')
                                          ->orWhere(function ($orQuery) {
                                              $orQuery->whereNotExists(function ($existsQuery) {
                                                  $existsQuery->select(DB::raw(1))
                                                      ->from('wallet_transfers as wt')
                                                      ->join('wallets as fw', 'wt.from_wallet_id', '=', 'fw.id')
                                                      ->join('wallets as tw', 'wt.to_wallet_id', '=', 'tw.id')
                                                      ->whereColumn('wt.id', 'transactions.source_id')
                                                      ->whereColumn('fw.user_id', 'tw.user_id');
                                              })
                                              ->whereNotExists(function ($existsQuery) {
                                                  $existsQuery->select(DB::raw(1))
                                                      ->from('savings_goals as sg')
                                                      ->whereColumn('sg.id', 'transactions.source_id');
                                              });
                                          });
                                  });
                          });
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

        // Luôn sắp xếp phụ theo id để đảm bảo tính duy nhất tuyệt đối cho cursor
        $query->orderBy('transactions.id', 'desc');

        return $query->cursorPaginate($perPage);
    }
}
