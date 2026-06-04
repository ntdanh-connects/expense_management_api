<?php

namespace App\Services;

use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class WalletService {
    protected $walletRepository;
    protected $exchangeRateService;

    public function __construct(
        WalletRepositoryInterface $walletRepository,
        ExchangeRateService $exchangeRateService
    ) {
        $this->walletRepository = $walletRepository;
        $this->exchangeRateService = $exchangeRateService;
    }

    public function getAllUserWallets(string $userId)
    {
        return $this->walletRepository->getWalletsByUserId($userId);
    }

    // 🔥 Kích hoạt tạo ví đồng bộ 2 bảng sạch sẽ theo SQL
    public function createNewWallet(string $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            $currencyCode = $data['currency_code'] ?? null;
            if (!$currencyCode) {
                $currencyCode = DB::table('user_preferences')->where('user_id', $userId)->value('currency') ?? 'VND';
            }

            // 1. Tạo ví mới cứng
            $wallet = $this->walletRepository->create([
                'user_id'       => $userId,
                'name'          => $data['name'],
                'type'          => $data['type'],
                'icon'          => $data['icon'] ?? null,
                'color'         => $data['color'] ?? null,
                'is_hidden'     => $data['is_hidden'] ?? false,
                'currency_code' => $currencyCode,
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
        
        Log::info("DEBUG UPDATE: wallet_id=$walletId, wallet_user_id=" . ($wallet ? $wallet->user_id : 'null') . ", request_user_id=$userId");

        if (!$wallet || $wallet->user_id !== $userId) {
            $wUid = $wallet ? $wallet->user_id : 'null';
            throw new \Exception(__('messages.wallet_unauthorized', ['wUid' => $wUid, 'rUid' => $userId]));
        }

        $wallet->update($data);

        // 🔥 Gắn thêm số dư hiện tại từ bảng wallet_balances để Frontend không bị mất số dư (về 0đ) khi cập nhật!
        $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_balance') ?? 0.00;
        $wallet->available_balance = (float)$balance;

        return $wallet;
    }

    public function deleteWallet(string $walletId, string $userId)
    {
        $wallet = $this->walletRepository->find($walletId);
        
        if (!$wallet || $wallet->user_id !== $userId) {
            throw new \Exception(__('messages.wallet_not_found_or_unauthorized'));
        }

        // Check số dư hiện tại từ bảng balance trước khi cho xóa mềm
        $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_balance');
        if ((float)$balance > 0) {
            throw new \Exception(__('messages.wallet_has_balance'));
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
                throw new \Exception(__('messages.wallets_same'));
            }

            if ($amount <= 0) {
                throw new \Exception(__('messages.amount_must_be_positive'));
            }

            // 1. Kiểm tra sự tồn tại và quyền sở hữu của 2 chiếc ví
            $fromWallet = $this->walletRepository->find($fromWalletId);
            $toWallet = $this->walletRepository->find($toWalletId);

            if (!$fromWallet || $fromWallet->user_id !== $userId) {
                throw new \Exception(__('messages.source_wallet_not_found'));
            }

            if (!$toWallet || $toWallet->user_id !== $userId) {
                throw new \Exception(__('messages.target_wallet_not_found'));
            }

            // 2. Lock bảng số dư để tránh race condition (ghi đè số dư nếu có 2 giao dịch cùng lúc)
            $fromBalance = DB::table('wallet_balances')->where('wallet_id', $fromWalletId)->lockForUpdate()->first();
            $toBalance = DB::table('wallet_balances')->where('wallet_id', $toWalletId)->lockForUpdate()->first();

            if (!$fromBalance) {
                throw new \Exception(__('messages.source_wallet_balance_not_found'));
            }

            if (!$toBalance) {
                throw new \Exception(__('messages.target_wallet_balance_not_found'));
            }

            if (bccomp($fromBalance->available_balance, $amount, 2) === -1) {
                throw new \Exception(__('messages.insufficient_balance'));
            }

            // Lấy timezone mặc định của user
            $userTimezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
            $timezone = $data['timezone'] ?? $userTimezone;

            // Lấy currency của 2 ví
            $fromWalletCurrency = $fromWallet->currency_code ?? 'VND';
            $toWalletCurrency = $toWallet->currency_code ?? 'VND';

            // Tra cứu tỷ giá giữa 2 ví
            $rate = $this->exchangeRateService->getRate($fromWalletCurrency, $toWalletCurrency);
            $convertedAmount = (float) bcmul(number_format($amount, 4, '.', ''), number_format($rate, 6, '.', ''), 4);

            // Quy đổi số tiền sang tiền tệ hiển thị của user (amount_in_user_currency)
            $userCurrency = DB::table('user_preferences')->where('user_id', $userId)->value('currency') ?? 'VND';
            
            if ($fromWalletCurrency === $userCurrency) {
                $expenseAmountInUserCurrency = $amount;
            } else {
                $fromRateToUser = $this->exchangeRateService->getRate($fromWalletCurrency, $userCurrency);
                $expenseAmountInUserCurrency = (float) bcmul(number_format($amount, 4, '.', ''), number_format($fromRateToUser, 6, '.', ''), 4);
            }

            if ($toWalletCurrency === $userCurrency) {
                $incomeAmountInUserCurrency = $convertedAmount;
            } else {
                $toRateToUser = $this->exchangeRateService->getRate($toWalletCurrency, $userCurrency);
                $incomeAmountInUserCurrency = (float) bcmul(number_format($convertedAmount, 4, '.', ''), number_format($toRateToUser, 6, '.', ''), 4);
            }

            $transferId = (string) Str::uuid7();
            $expenseId = (string) Str::uuid7();
            $incomeId = (string) Str::uuid7();

            // 4. Tạo giao dịch đối ứng 1: Chi tiền (Expense) từ ví nguồn
            DB::table('transactions')->insert([
                'id'                      => $expenseId,
                'user_id'                 => $userId,
                'wallet_id'               => $fromWalletId,
                'category_id'             => null, // Chuyển khoản không ghi nhận vào danh mục chi tiêu bình thường
                'type'                    => 'expense',
                'status'                  => 'completed',
                'amount'                  => $amount,
                'currency_code'           => $fromWalletCurrency,
                'exchange_rate'           => 1.000000,
                'amount_in_user_currency' => $expenseAmountInUserCurrency,
                'title'                   => $notes ?? __('messages.transfer_out_title', ['name' => $toWallet->name]),
                'notes'                   => $notes,
                'transaction_date'        => now(),
                'source_type'             => 'transfer',
                'source_id'               => $transferId,
                'timezone'                => $timezone,
                'created_at'              => now(),
                'updated_at'              => now()
            ]);

            // 5. Tạo giao dịch đối ứng 2: Thu tiền (Income) vào ví đích
            DB::table('transactions')->insert([
                'id'                      => $incomeId,
                'user_id'                 => $userId,
                'wallet_id'               => $toWalletId,
                'category_id'             => null,
                'type'                    => 'income',
                'status'                  => 'completed',
                'amount'                  => $convertedAmount,
                'currency_code'           => $toWalletCurrency,
                'exchange_rate'           => 1.000000,
                'amount_in_user_currency' => $incomeAmountInUserCurrency,
                'title'                   => $notes ?? __('messages.transfer_in_title', ['name' => $fromWallet->name]),
                'notes'                   => $notes,
                'transaction_date'        => now(),
                'source_type'             => 'transfer',
                'source_id'               => $transferId,
                'timezone'                => $timezone,
                'created_at'              => now(),
                'updated_at'              => now()
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
                'timezone'               => $timezone,
                'created_at'             => now()
            ]);

            // 7. Cập nhật số dư 2 ví trong bảng wallet_balances bằng BCMath chính xác tuyệt đối
            DB::table('wallet_balances')->where('wallet_id', $fromWalletId)->update([
                'available_balance'   => bcsub($fromBalance->available_balance, $amount, 2),
                'last_transaction_id' => $expenseId,
                'updated_at'          => now()
            ]);

            DB::table('wallet_balances')->where('wallet_id', $toWalletId)->update([
                'available_balance'   => bcadd($toBalance->available_balance, $convertedAmount, 2),
                'last_transaction_id' => $incomeId,
                'updated_at'          => now()
            ]);

            return [
                'transfer_id'            => $transferId,
                'expense_transaction_id' => $expenseId,
                'income_transaction_id'  => $incomeId,
                'amount'                 => $amount,
                'converted_amount'       => $convertedAmount,
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
            throw new \Exception(__('messages.history_unauthorized'));
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
                'transactions.timezone',
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