<?php

namespace App\Services;

use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction;
use App\Events\TransactionSaved;
use Illuminate\Support\Facades\Http;
use App\Models\User;

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

    // Kích hoạt tạo ví đồng bộ 2 bảng sạch sẽ theo SQL
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
                'minimum_balance' => $data['minimum_balance'] ?? null,
                'is_minimum_balance_alert_enabled' => $data['is_minimum_balance_alert_enabled'] ?? true,
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

        $oldAlertEnabled = (bool) $wallet->is_minimum_balance_alert_enabled;
        $newAlertEnabled = isset($data['is_minimum_balance_alert_enabled']) ? (bool) $data['is_minimum_balance_alert_enabled'] : $oldAlertEnabled;
        
        $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_balance') ?? 0.00;
        $shouldResetAlert = false;

        if (!$oldAlertEnabled && $newAlertEnabled) {
            $shouldResetAlert = true;
        }

        if (isset($data['is_minimum_balance_alert_enabled']) && !$data['is_minimum_balance_alert_enabled']) {
            $shouldResetAlert = true;
        }

        if (array_key_exists('minimum_balance', $data)) {
            $oldMin = $wallet->minimum_balance;
            $newMin = $data['minimum_balance'];
            if ($oldMin != $newMin || $newMin === null || (float)$balance >= (float)$newMin) {
                $shouldResetAlert = true;
            }
        }

        if ($shouldResetAlert) {
            $data['last_alert_sent_at'] = null;
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

            // 2. Lock bảng số dư để tránh race condition theo thứ tự ID tăng dần (chống Deadlock)
            $sortedWalletIds = [$fromWalletId, $toWalletId];
            sort($sortedWalletIds);

            $balances = [];
            foreach ($sortedWalletIds as $wId) {
                $balances[$wId] = DB::table('wallet_balances')->where('wallet_id', $wId)->lockForUpdate()->first();
            }

            $fromBalance = $balances[$fromWalletId];
            $toBalance = $balances[$toWalletId];

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

            if ($fromWalletCurrency !== $toWalletCurrency) {
                throw new \Exception('Chuyển khoản nội bộ giữa hai ví có đơn vị tiền tệ khác nhau không được hỗ trợ.');
            }

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

            // Bắn sự kiện TransactionSaved để xóa cache và cập nhật thống kê
            $expenseTx = Transaction::find($expenseId);
            $incomeTx = Transaction::find($incomeId);
            if ($expenseTx) {
                event(new TransactionSaved($expenseTx));
            }
            if ($incomeTx) {
                event(new TransactionSaved($incomeTx));
            }

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

    /**
     * Chuyển tiền P2P giữa 2 người dùng khác nhau trong hệ thống
     */
    public function p2pTransfer(string $fromUserId, string $toUserId, string $fromWalletId, string $toWalletId, float $amount, ?string $notes = null, ?string $timezone = null, ?string $payeeId = null, bool $isQr = true, ?string $categoryId = null, ?string $transactionDate = null)
    {
        return DB::transaction(function () use ($fromUserId, $toUserId, $fromWalletId, $toWalletId, $amount, $notes, $timezone, $payeeId, $isQr, $categoryId, $transactionDate) {
            if ($fromWalletId === $toWalletId) {
                throw new \Exception(__('messages.wallets_same'));
            }

            if ($amount <= 0) {
                throw new \Exception(__('messages.amount_must_be_positive'));
            }

            $fromWallet = $this->walletRepository->find($fromWalletId);
            $toWallet = $this->walletRepository->find($toWalletId);

            if (!$fromWallet || $fromWallet->user_id !== $fromUserId) {
                throw new \Exception(__('messages.source_wallet_not_found'));
            }

            if (!$toWallet || $toWallet->user_id !== $toUserId) {
                throw new \Exception(__('messages.target_wallet_not_found'));
            }

            $fromBalance = DB::table('wallet_balances')->where('wallet_id', $fromWalletId)->lockForUpdate()->first();
            $toBalance = DB::table('wallet_balances')->where('wallet_id', $toWalletId)->lockForUpdate()->first();

            if (!$fromBalance || !$toBalance) {
                throw new \Exception("Không tìm thấy thông tin số dư của ví.");
            }

            if (bccomp($fromBalance->available_balance, $amount, 2) === -1) {
                throw new \Exception(__('messages.insufficient_balance'));
            }

            $userTimezone = DB::table('user_preferences')->where('user_id', $fromUserId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
            $timezone = $timezone ?? $userTimezone;

            $fromCurrency = $fromWallet->currency_code ?? 'VND';
            $toCurrency = $toWallet->currency_code ?? 'VND';

            $rate = $this->exchangeRateService->getRate($fromCurrency, $toCurrency);
            $convertedAmount = (float) bcmul(number_format($amount, 4, '.', ''), number_format($rate, 6, '.', ''), 4);

            // Quy đổi số tiền sang tiền tệ hiển thị của người gửi
            $fromUserCurrency = DB::table('user_preferences')->where('user_id', $fromUserId)->value('currency') ?? 'VND';
            $expenseAmountInUserCurrency = ($fromCurrency === $fromUserCurrency) ? $amount : 
                (float) bcmul(number_format($amount, 4, '.', ''), number_format($this->exchangeRateService->getRate($fromCurrency, $fromUserCurrency), 6, '.', ''), 4);

            // Quy đổi số tiền sang tiền tệ hiển thị của người nhận
            $toUserCurrency = DB::table('user_preferences')->where('user_id', $toUserId)->value('currency') ?? 'VND';
            $incomeAmountInUserCurrency = ($toCurrency === $toUserCurrency) ? $convertedAmount : 
                (float) bcmul(number_format($convertedAmount, 4, '.', ''), number_format($this->exchangeRateService->getRate($toCurrency, $toUserCurrency), 6, '.', ''), 4);

            $transferId = (string) Str::uuid7();
            $expenseId = (string) Str::uuid7();
            $incomeId = (string) Str::uuid7();

            $recipientProfile = DB::table('user_profiles')->where('user_id', $toUserId)->first();
            $recipientName = $recipientProfile ? $recipientProfile->full_name : 'Người dùng';

            $senderProfile = DB::table('user_profiles')->where('user_id', $fromUserId)->first();
            $senderName = $senderProfile ? $senderProfile->full_name : 'Người gửi';

            $expenseTitle = $isQr ? "Chuyển tiền qua mã QR đến {$recipientName}" : "Chuyển tiền đến {$recipientName}";
            $expenseCategoryId = $categoryId ?? $this->autoClassifyCategory($fromUserId, $expenseTitle, $notes, 'expense');

            $incomeTitle = $isQr ? "Nhận tiền từ {$senderName} qua mã QR" : "Nhận tiền từ {$senderName}";
            $incomeCategoryId = $this->autoClassifyCategory($toUserId, $incomeTitle, $notes, 'income');

            // Giao dịch 1: Chi tiền từ người gửi
            DB::table('transactions')->insert([
                'id'                      => $expenseId,
                'user_id'                 => $fromUserId,
                'wallet_id'               => $fromWalletId,
                'category_id'             => $expenseCategoryId,
                'payee_id'                => $payeeId,
                'type'                    => 'expense',
                'status'                  => 'completed',
                'amount'                  => $amount,
                'currency_code'           => $fromCurrency,
                'exchange_rate'           => 1.000000,
                'amount_in_user_currency' => $expenseAmountInUserCurrency,
                'title'                   => $expenseTitle,
                'notes'                   => $notes,
                'transaction_date'        => $transactionDate ? \Illuminate\Support\Carbon::parse($transactionDate) : now(),
                'source_type'             => 'transfer',
                'source_id'               => $transferId,
                'timezone'                => $timezone,
                'created_at'              => now(),
                'updated_at'              => now()
            ]);

            // Giao dịch 2: Nhận tiền từ người nhận
            DB::table('transactions')->insert([
                'id'                      => $incomeId,
                'user_id'                 => $toUserId,
                'wallet_id'               => $toWalletId,
                'category_id'             => $incomeCategoryId,
                'type'                    => 'income',
                'status'                  => 'completed',
                'amount'                  => $convertedAmount,
                'currency_code'           => $toCurrency,
                'exchange_rate'           => 1.000000,
                'amount_in_user_currency' => $incomeAmountInUserCurrency,
                'title'                   => $incomeTitle,
                'notes'                   => $notes,
                'transaction_date'        => $transactionDate ? \Illuminate\Support\Carbon::parse($transactionDate) : now(),
                'source_type'             => 'transfer',
                'source_id'               => $transferId,
                'timezone'                => DB::table('user_preferences')->where('user_id', $toUserId)->value('timezone') ?? 'Asia/Ho_Chi_Minh',
                'created_at'              => now(),
                'updated_at'              => now()
            ]);

            // Ghi nhận vào bảng wallet_transfers
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

            // Cập nhật số dư
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

            // Bắn sự kiện TransactionSaved để xóa cache và cập nhật thống kê
            $expenseTx = Transaction::find($expenseId);
            $incomeTx = Transaction::find($incomeId);
            if ($expenseTx) {
                event(new TransactionSaved($expenseTx));
            }
            if ($incomeTx) {
                event(new TransactionSaved($incomeTx));
            }

            // Gửi thông báo nhận tiền P2P cho người thụ hưởng
            try {
                $recipient = User::find($toUserId);
                if ($recipient) {
                    $recipient->notify(new \App\Notifications\P2pTransferReceivedNotification(
                        $senderName,
                        $convertedAmount,
                        $toCurrency,
                        $notes
                    ));
                }
            } catch (\Throwable $e) {
                Log::error("Lỗi khi gửi thông báo chuyển tiền P2P: " . $e->getMessage());
            }

            return [
                'transfer_id' => $transferId,
                'expense_id' => $expenseId,
                'income_id' => $incomeId,
                'amount' => $amount,
                'recipient_name' => $recipientName
            ];
        });
    }

    /**
     * Chuyển tiền ảo đến tài khoản ngân hàng ngoài (VietQR)
     */
    public function bankTransfer(string $userId, string $fromWalletId, string $bankCode, string $accountNumber, string $accountName, float $amount, ?string $notes = null, ?string $timezone = null, ?string $payeeId = null, bool $isQr = true, ?string $categoryId = null)
    {
        return DB::transaction(function () use ($userId, $fromWalletId, $bankCode, $accountNumber, $accountName, $amount, $notes, $timezone, $payeeId, $isQr, $categoryId) {
            if ($amount <= 0) {
                throw new \Exception(__('messages.amount_must_be_positive'));
            }

            $fromWallet = $this->walletRepository->find($fromWalletId);

            if (!$fromWallet || $fromWallet->user_id !== $userId) {
                throw new \Exception(__('messages.source_wallet_not_found'));
            }

            $fromBalance = DB::table('wallet_balances')->where('wallet_id', $fromWalletId)->lockForUpdate()->first();

            if (!$fromBalance) {
                throw new \Exception("Không tìm thấy thông tin số dư của ví.");
            }

            if (bccomp($fromBalance->available_balance, $amount, 2) === -1) {
                throw new \Exception(__('messages.insufficient_balance'));
            }

            $userTimezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
            $timezone = $timezone ?? $userTimezone;

            $fromCurrency = $fromWallet->currency_code ?? 'VND';
            $userCurrency = DB::table('user_preferences')->where('user_id', $userId)->value('currency') ?? 'VND';
            
            $expenseAmountInUserCurrency = ($fromCurrency === $userCurrency) ? $amount : 
                (float) bcmul(number_format($amount, 4, '.', ''), number_format($this->exchangeRateService->getRate($fromCurrency, $userCurrency), 6, '.', ''), 4);

            $expenseId = (string) Str::uuid7();

            $expenseTitle = "Thanh toán cho {$accountName}";
            $expenseCategoryId = $categoryId ?? $this->autoClassifyCategory($userId, $expenseTitle, $notes, 'expense');

            // Giao dịch Chi tiêu (Expense) của ví nguồn
            DB::table('transactions')->insert([
                'id'                      => $expenseId,
                'user_id'                 => $userId,
                'wallet_id'               => $fromWalletId,
                'category_id'             => $expenseCategoryId,
                'payee_id'                => $payeeId,
                'type'                    => 'expense',
                'status'                  => 'completed',
                'amount'                  => $amount,
                'currency_code'           => $fromCurrency,
                'exchange_rate'           => 1.000000,
                'amount_in_user_currency' => $expenseAmountInUserCurrency,
                'title'                   => $expenseTitle,
                'notes'                   => $notes,
                'transaction_date'        => now(),
                'source_type'             => 'transfer',
                'source_id'               => null, // Không có ví đối ứng trong app
                'timezone'                => $timezone,
                'created_at'              => now(),
                'updated_at'              => now()
            ]);

            // Cập nhật số dư
            DB::table('wallet_balances')->where('wallet_id', $fromWalletId)->update([
                'available_balance'   => bcsub($fromBalance->available_balance, $amount, 2),
                'last_transaction_id' => $expenseId,
                'updated_at'          => now()
            ]);

            // Bắn sự kiện TransactionSaved để xóa cache và cập nhật thống kê
            $expenseTx = Transaction::find($expenseId);
            if ($expenseTx) {
                event(new TransactionSaved($expenseTx));
            }

            return [
                'expense_id' => $expenseId,
                'amount' => $amount,
                'payee_name' => $accountName
            ];
        });
    }

    /**
     * Tự động phân loại danh mục bằng AI cho giao dịch mới tạo
     */
    protected function autoClassifyCategory(string $userId, ?string $title, ?string $notes, string $type): ?string
    {
        try {
            $apiKey = env('GEMINI_API_KEY');
            if (!$apiKey) {
                return null;
            }

            $title = trim($title ?? '');
            $notes = trim($notes ?? '');
            if (empty($title) && empty($notes)) {
                return null;
            }

            // Lấy toàn bộ danh mục của user
            $categoryService = app(\App\Services\CategoryService::class);
            $categories = $categoryService->getCategoriesTree($userId);

            // Lọc danh mục cha có type tương ứng
            $filteredParents = $categories->where('type', $type);

            // Dựng danh sách phẳng các danh mục lá
            $categoriesList = [];
            foreach ($filteredParents as $parent) {
                $children = $parent->children ?? collect();
                if ($children->isEmpty()) {
                    $categoriesList[] = [
                        'id' => $parent->id,
                        'name' => $parent->name,
                        'parent_name' => null
                    ];
                } else {
                    foreach ($children as $child) {
                        $categoriesList[] = [
                            'id' => $child->id,
                            'name' => $child->name,
                            'parent_name' => $parent->name
                        ];
                    }
                }
            }

            if (empty($categoriesList)) {
                return null;
            }

            $model = env('GEMINI_MODEL', 'gemini-3.5-flash');

            $prompt = "Dựa trên tiêu đề giao dịch và ghi chú dưới đây, hãy chọn danh mục phù hợp nhất từ danh sách danh mục có sẵn.\n"
                . "Tiêu đề: " . ($title ?: '(Trống)') . "\n"
                . "Ghi chú/Nội dung: " . ($notes ?: '(Trống)') . "\n"
                . "Loại giao dịch: " . $type . "\n\n"
                . "Danh sách danh mục có sẵn (gồm ID, tên danh mục, và tên danh mục cha nếu có):\n"
                . json_encode($categoriesList, JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Yêu cầu:\n"
                . "Trả về duy nhất một đối tượng JSON có dạng:\n"
                . "{\"category_id\": \"<ID danh mục được chọn>\"}\n"
                . "Nếu không khớp danh mục nào phù hợp, hãy trả về:\n"
                . "{\"category_id\": null}";

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $prompt]]
                    ]
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ]
            ];

            $response = Http::timeout(5)->post($url, $payload);

            if ($response->failed()) {
                return null;
            }

            $result = $response->json();
            $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!$text) {
                return null;
            }

            $data = json_decode(trim($text), true);
            $categoryId = $data['category_id'] ?? null;

            // Chốt chặn an toàn
            $validIds = array_column($categoriesList, 'id');
            if ($categoryId && in_array($categoryId, $validIds)) {
                return $categoryId;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('AI Auto-classification failed in WalletService: ' . $e->getMessage());
            return null;
        }
    }
}