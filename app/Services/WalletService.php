<?php

namespace App\Services;

use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService {
    protected $walletRepository;

    public function __construct(WalletRepositoryInterface $walletRepository)
    {
        $this->walletRepository = $walletRepository;
    }

    public function getAllUserWallets(string $userId)
    {
        return $this->walletRepository->getWalletsByUserId($userId);
    }

    // 🔥 Kích hoạt tạo ví đồng bộ 2 bảng sạch sẽ theo SQL
    public function createNewWallet(string $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            
            // 1. Tạo ví mới cứng
            $wallet = $this->walletRepository->create([
                'user_id'   => $userId,
                'name'      => $data['name'],
                'type'      => $data['type'],
                'icon'      => $data['icon'] ?? null,
                'color'     => $data['color'] ?? null,
                'is_hidden' => $data['is_hidden'] ?? false,
            ]);

            // 2. Chọc sang bảng wallet_balances găm số dư khả dụng ban đầu
            $initialBalance = (float) ($data['available_balance'] ?? 0.00);
            $this->walletRepository->initWalletBalance($wallet->id, $initialBalance);

            // Gắn số dư tạm thời vào object trả về cho Frontend tiện đọc
            $wallet->available_balance = $initialBalance;
            
            return $wallet;
        });
    }

    public function updateWallet(string $walletId, string $userId, array $data)
    {
        $wallet = $this->walletRepository->find($walletId);
        
        if (!$wallet || $wallet->user_id !== $userId) {
            throw new \Exception("Ví không tồn tại hoặc bạn không có quyền chỉnh sửa!");
        }

        $wallet->update($data);
        return $wallet;
    }

    public function deleteWallet(string $walletId, string $userId)
    {
        $wallet = $this->walletRepository->find($walletId);
        
        if (!$wallet || $wallet->user_id !== $userId) {
            throw new \Exception("Ví không tồn tại hoặc không có quyền thao tác!");
        }

        // Check số dư hiện tại từ bảng balance trước khi cho xóa mềm
        $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_balance');
        if ((float)$balance > 0) {
            throw new \Exception("Ví hiện tại vẫn còn tiền dư, không thể xóa!");
        }

        return $wallet->delete();
    }

    /**
     * Chuyển tiền giữa các ví (Transfer) và tự động tạo 2 giao dịch đối ứng (Thu/Chi)
     */
    public function transferMoney(string $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            $fromWalletId = $data['from_wallet_id'];
            $toWalletId = $data['to_wallet_id'];
            $amount = (float) $data['amount'];
            $notes = $data['notes'] ?? null;

            if ($fromWalletId === $toWalletId) {
                throw new \Exception("Ví chuyển và ví nhận không thể trùng nhau!");
            }

            if ($amount <= 0) {
                throw new \Exception("Số tiền chuyển phải lớn hơn 0!");
            }

            // 1. Kiểm tra sự tồn tại và quyền sở hữu của 2 chiếc ví
            $fromWallet = $this->walletRepository->find($fromWalletId);
            $toWallet = $this->walletRepository->find($toWalletId);

            if (!$fromWallet || $fromWallet->user_id !== $userId) {
                throw new \Exception("Ví chuyển không tồn tại hoặc bạn không có quyền sở hữu!");
            }

            if (!$toWallet || $toWallet->user_id !== $userId) {
                throw new \Exception("Ví nhận không tồn tại hoặc bạn không có quyền sở hữu!");
            }

            // 2. Lock bảng số dư để tránh race condition (ghi đè số dư nếu có 2 giao dịch cùng lúc)
            $fromBalance = DB::table('wallet_balances')->where('wallet_id', $fromWalletId)->lockForUpdate()->first();
            $toBalance = DB::table('wallet_balances')->where('wallet_id', $toWalletId)->lockForUpdate()->first();

            if (!$fromBalance) {
                throw new \Exception("Không tìm thấy dữ liệu số dư của ví chuyển!");
            }

            if (!$toBalance) {
                throw new \Exception("Không tìm thấy dữ liệu số dư của ví nhận!");
            }

            if (bccomp($fromBalance->available_balance, $amount, 2) === -1) {
                throw new \Exception("Số dư khả dụng của ví chuyển không đủ để thực hiện giao dịch này!");
            }

            // Lấy currency mặc định của user
            $currency = DB::table('user_preferences')->where('user_id', $userId)->value('currency') ?? 'VND';

            $transferId = (string) Str::uuid7();
            $expenseId = (string) Str::uuid7();
            $incomeId = (string) Str::uuid7();

            // 4. Tạo giao dịch đối ứng 1: Chi tiền (Expense) từ ví nguồn
            DB::table('transactions')->insert([
                'id'               => $expenseId,
                'user_id'          => $userId,
                'wallet_id'        => $fromWalletId,
                'category_id'      => null, // Chuyển khoản không ghi nhận vào danh mục chi tiêu bình thường
                'type'             => 'expense',
                'status'           => 'completed',
                'amount'           => $amount,
                'currency_code'    => $currency,
                'exchange_rate'    => 1.000000,
                'title'            => $notes ?? "Chuyển tiền sang ví " . $toWallet->name,
                'notes'            => $notes,
                'transaction_date' => now(),
                'source_type'      => 'transfer',
                'source_id'        => $transferId,
                'created_at'       => now(),
                'updated_at'       => now()
            ]);

            // 5. Tạo giao dịch đối ứng 2: Thu tiền (Income) vào ví đích
            DB::table('transactions')->insert([
                'id'               => $incomeId,
                'user_id'          => $userId,
                'wallet_id'        => $toWalletId,
                'category_id'      => null,
                'type'             => 'income',
                'status'           => 'completed',
                'amount'           => $amount,
                'currency_code'    => $currency,
                'exchange_rate'    => 1.000000,
                'title'            => $notes ?? "Nhận tiền từ ví " . $fromWallet->name,
                'notes'            => $notes,
                'transaction_date' => now(),
                'source_type'      => 'transfer',
                'source_id'        => $transferId,
                'created_at'       => now(),
                'updated_at'       => now()
            ]);

            // 6. Ghi nhận giao dịch chuyển khoản vào bảng wallet_transfers
            DB::table('wallet_transfers')->insert([
                'id'                     => $transferId,
                'from_wallet_id'         => $fromWalletId,
                'to_wallet_id'           => $toWalletId,
                'amount'                 => $amount,
                'expense_transaction_id' => $expenseId,
                'income_transaction_id'  => $incomeId,
                'transferred_at'         => now(),
                'created_at'             => now()
            ]);

            // 7. Cập nhật số dư 2 ví trong bảng wallet_balances bằng BCMath chính xác tuyệt đối
            DB::table('wallet_balances')->where('wallet_id', $fromWalletId)->update([
                'available_balance'   => bcsub($fromBalance->available_balance, $amount, 2),
                'last_transaction_id' => $expenseId,
                'updated_at'          => now()
            ]);

            DB::table('wallet_balances')->where('wallet_id', $toWalletId)->update([
                'available_balance'   => bcadd($toBalance->available_balance, $amount, 2),
                'last_transaction_id' => $incomeId,
                'updated_at'          => now()
            ]);

            return [
                'transfer_id'            => $transferId,
                'expense_transaction_id' => $expenseId,
                'income_transaction_id'  => $incomeId,
                'amount'                 => $amount,
                'from_wallet'            => $fromWallet->name,
                'to_wallet'              => $toWallet->name
            ];
        });
    }

    /**
     * Lấy lịch sử giao dịch theo từng ví riêng biệt (Có phân trang)
     */
    public function getWalletTransactions(string $walletId, string $userId, int $perPage = 20)
    {
        $wallet = $this->walletRepository->find($walletId);
        
        if (!$wallet || $wallet->user_id !== $userId) {
            throw new \Exception("Ví không tồn tại hoặc bạn không có quyền truy cập lịch sử giao dịch!");
        }

        return DB::table('transactions')
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->where('transactions.wallet_id', $walletId)
            ->whereNull('transactions.deleted_at')
            ->select(
                'transactions.id',
                'transactions.type',
                'transactions.status',
                'transactions.amount',
                'transactions.currency_code',
                'transactions.title',
                'transactions.notes',
                'transactions.transaction_date',
                'transactions.source_type',
                'transactions.source_id',
                'categories.name as category_name',
                'categories.icon as category_icon',
                'categories.color as category_color'
            )
            ->orderBy('transactions.transaction_date', 'desc')
            ->orderBy('transactions.created_at', 'desc')
            ->paginate($perPage);
    }
}