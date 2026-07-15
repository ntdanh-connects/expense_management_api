<?php

namespace App\Services;

use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\TransactionAudit;
use App\Services\ExchangeRateService;
use App\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TransactionService
{
    protected $transactionRepository;
    protected $imageUploadService;
    protected $exchangeRateService;

    public function __construct(
        TransactionRepositoryInterface $transactionRepository,
        ImageUploadService $imageUploadService,
        ExchangeRateService $exchangeRateService
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->imageUploadService = $imageUploadService;
        $this->exchangeRateService = $exchangeRateService;
    }

    public function getTransactions(string $userId, array $filters, string $sortBy = 'date', string $sortOrder = 'desc', int $perPage = 20)
    {
        return $this->transactionRepository->getFilteredTransactions($userId, $filters, $sortBy, $sortOrder, $perPage);
    }

    public function getTransactionById(string $id, string $userId)
    {
        $transaction = Transaction::with(['category', 'wallet', 'attachments', 'payee', 'splits.wallet'])->find($id);

        if (!$transaction || $transaction->user_id !== $userId) {
            throw new \Exception(__('messages.transaction_not_found_or_unauthorized'));
        }

        return $transaction;
    }

    /**
     * Tạo giao dịch thủ công kèm đính kèm
     */
    public function createTransaction(string $userId, array $data, ?UploadedFile $attachment = null, ?array $attachments = null)
    {
        return DB::transaction(function () use ($userId, $data, $attachment, $attachments) {
            // Check if transaction with this ID already exists to prevent duplicates on retry
            if (!empty($data['id'])) {
                $existing = Transaction::with(['category', 'wallet', 'attachments', 'payee'])
                    ->where('id', $data['id'])
                    ->where('user_id', $userId)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            // MỚI: Xử lý giao dịch split (thanh toán kết hợp nhiều ví)
            $isSplit = isset($data['splits']) && is_array($data['splits']) && count($data['splits']) > 1;

            if ($isSplit) {
                if (($data['type'] ?? 'expense') !== 'expense') {
                    throw new \Exception("Chỉ cho phép thanh toán kết hợp nhiều ví đối với giao dịch chi tiêu (expense).");
                }

                $splitsData = $data['splits'];
                
                // 1. Kiểm tra lặp ví
                $walletIdsInSplits = array_column($splitsData, 'wallet_id');
                if (count($walletIdsInSplits) !== count(array_unique($walletIdsInSplits))) {
                    throw new \Exception("Không được chọn trùng lặp ví trong cùng một giao dịch.");
                }

                // 2. Sắp xếp ví theo ID tăng dần để tránh Deadlock
                usort($splitsData, function ($a, $b) {
                    return strcmp($a['wallet_id'], $b['wallet_id']);
                });

                // 3. Quy đổi tiền tệ hiển thị của user
                $userCurrency = DB::table('user_preferences')->where('user_id', $userId)->value('currency') ?? 'VND';
                $totalSplitUserAmount = 0.0;
                
                $preparedSplits = [];
                $walletBalancesToUpdate = [];

                foreach ($splitsData as $splitItem) {
                    $splitWalletId = $splitItem['wallet_id'];
                    $splitAmount = (float) $splitItem['amount'];

                    // Kiểm tra ví sở hữu
                    $splitWallet = DB::table('wallets')
                        ->where('id', $splitWalletId)
                        ->where('user_id', $userId)
                        ->whereNull('deleted_at')
                        ->first();

                    if (!$splitWallet) {
                        throw new \Exception("Không tìm thấy ví hoặc bạn không có quyền sử dụng ví này.");
                    }

                    $splitWalletCurrency = $splitWallet->currency_code ?? 'VND';
                    
                    // Tính tỷ giá từ ví sang user currency
                    $splitRate = $this->exchangeRateService->getRate($splitWalletCurrency, $userCurrency);
                    $splitAmountInUserCurrency = (float) bcmul((string)$splitAmount, sprintf('%.6f', $splitRate), 4);
                    
                    $totalSplitUserAmount += $splitAmountInUserCurrency;

                    // Khóa số dư ví
                    $splitWalletBalance = DB::table('wallet_balances')
                        ->where('wallet_id', $splitWalletId)
                        ->lockForUpdate()
                        ->first();

                    if (!$splitWalletBalance) {
                        throw new \Exception("Không tìm thấy số dư ví.");
                    }

                    // Kiểm tra số dư khả dụng
                    if (bccomp($splitWalletBalance->available_balance, (string)$splitAmount, 2) === -1) {
                        throw new \Exception("Số dư Ví '{$splitWallet->name}' không đủ để thực hiện giao dịch.");
                    }

                    $newSplitBalance = (float) bcsub($splitWalletBalance->available_balance, (string)$splitAmount, 2);

                    $preparedSplits[] = [
                        'wallet_id' => $splitWalletId,
                        'amount' => $splitAmount,
                        'amount_in_user_currency' => $splitAmountInUserCurrency,
                        'exchange_rate' => $splitRate,
                    ];

                    $walletBalancesToUpdate[] = [
                        'wallet_id' => $splitWalletId,
                        'new_balance' => $newSplitBalance,
                    ];
                }

                // 4. Validate tổng tiền giao dịch chính
                $txCurrency = $data['currency_code'] ?? $userCurrency;
                $amount = (float) $data['amount'];
                if ($txCurrency === $userCurrency) {
                    $amountInUserCurrency = $amount;
                } else {
                    $rateToUserCurrency = $this->exchangeRateService->getRate($txCurrency, $userCurrency);
                    $amountInUserCurrency = (float) bcmul((string)$amount, sprintf('%.6f', $rateToUserCurrency), 4);
                }

                // Validate sai số tối đa 0.02
                if (abs($totalSplitUserAmount - $amountInUserCurrency) > 0.02) {
                    throw new \Exception("Tổng số tiền phân tách của các ví (" . number_format($totalSplitUserAmount, 2) . " " . $userCurrency . ") không khớp với số tiền giao dịch chính (" . number_format($amountInUserCurrency, 2) . " " . $userCurrency . "). Sai lệch tối đa cho phép là 0.02.");
                }

                // 5. Tạo giao dịch chính (wallet_id = null, is_split = true, amount = null)
                $userTimezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
                $timezone = $data['timezone'] ?? $userTimezone;
                $transactionId = $data['id'] ?? (string) Str::uuid7();

                // Tự động phân loại danh mục bằng AI nếu category_id trống
                $categoryId = $data['category_id'] ?? null;
                if (empty($categoryId)) {
                    $categoryId = $this->autoClassifyCategory($userId, $data['title'] ?? null, $data['notes'] ?? null, 'expense');
                }

                $txTitle = $data['title'] ?? null;
                if (empty($txTitle)) {
                    if ($categoryId) {
                        $categoryName = DB::table('categories')->where('id', $categoryId)->value('name');
                        $txTitle = $categoryName ?: 'Chi tiêu kết hợp';
                    } else {
                        $txTitle = 'Chi tiêu kết hợp';
                    }
                }

                $transaction = Transaction::create([
                    'id' => $transactionId,
                    'user_id' => $userId,
                    'wallet_id' => null,
                    'category_id' => $categoryId,
                    'payee_id' => $data['payee_id'] ?? null,
                    'type' => 'expense',
                    'status' => $data['status'] ?? 'completed',
                    'amount' => null,
                    'currency_code' => $txCurrency,
                    'exchange_rate' => $data['exchange_rate'] ?? 1.0,
                    'amount_in_user_currency' => $amountInUserCurrency,
                    'title' => $txTitle,
                    'notes' => $data['notes'] ?? null,
                    'timezone' => $timezone,
                    'transaction_date' => $data['transaction_date'] ?? now(),
                    'source_type' => $data['source_type'] ?? 'manual',
                    'source_id' => $data['source_id'] ?? null,
                    'is_split' => true,
                ]);

                // 6. Lưu trữ các splits
                foreach ($preparedSplits as $prepSplit) {
                    DB::table('transaction_splits')->insert([
                        'id' => (string) Str::uuid(),
                        'transaction_id' => $transactionId,
                        'wallet_id' => $prepSplit['wallet_id'],
                        'amount' => $prepSplit['amount'],
                        'amount_in_user_currency' => $prepSplit['amount_in_user_currency'],
                        'exchange_rate' => $prepSplit['exchange_rate'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // 7. Cập nhật số dư các ví
                foreach ($walletBalancesToUpdate as $wb) {
                    DB::table('wallet_balances')->where('wallet_id', $wb['wallet_id'])->update([
                        'available_balance' => $wb['new_balance'],
                        'last_transaction_id' => $transactionId,
                        'updated_at' => now()
                    ]);
                }

                // 8. Xử lý đính kèm nếu có
                $filesToUpload = [];
                if ($attachment) {
                    $filesToUpload[] = $attachment;
                }
                if ($attachments) {
                    foreach ($attachments as $file) {
                        if ($file instanceof UploadedFile) {
                            $filesToUpload[] = $file;
                        }
                    }
                }

                $s3Key = config('filesystems.disks.s3.key');
                $s3Secret = config('filesystems.disks.s3.secret');
                $s3Bucket = config('filesystems.disks.s3.bucket');
                $provider = (!empty($s3Key) && !empty($s3Secret) && !empty($s3Bucket)) ? 's3' : 'local';

                foreach ($filesToUpload as $file) {
                    $fileUrl = $this->imageUploadService->uploadToS3($file, 'receipts');
                    TransactionAttachment::create([
                        'id' => (string) Str::uuid7(),
                        'transaction_id' => $transactionId,
                        'storage_provider_enum' => $provider,
                        'file_key' => $fileUrl,
                        'file_url' => $fileUrl,
                        'mime_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'uploaded_at' => now()
                    ]);
                }

                // 9. Ghi log audit
                TransactionAudit::create([
                    'transaction_id' => $transactionId,
                    'old_data' => null,
                    'new_data' => $transaction->toArray(),
                    'changed_by' => $userId
                ]);

                // Bắn sự kiện TransactionSaved
                event(new \App\Events\TransactionSaved($transaction));

                return $transaction->load('category', 'wallet', 'attachments', 'splits.wallet');
            }

            $walletId = $data['wallet_id'];
            $categoryId = $data['category_id'] ?? null;
            $amount = (float) $data['amount'];
            $type = $data['type']; // income, expense

            // 1. Kiểm tra ví sở hữu của user
            $wallet = DB::table('wallets')
                ->where('id', $walletId)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->first();

            if (!$wallet) {
                throw new \Exception(__('messages.wallet_not_found_or_unauthorized'));
            }

            // 2. Kiểm tra danh mục sở hữu hoặc là danh mục mặc định
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

            $walletCurrency = $wallet->currency_code ?? 'VND';

            // Lấy timezone mặc định của user
            $userTimezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
            $timezone = $data['timezone'] ?? $userTimezone;

            // 2.5. Kiểm tra xem giao dịch này đã được ghi nhận tự động hôm nay theo lịch định kỳ chưa
            // (Chỉ áp dụng cho các giao dịch tạo thủ công từ phía người dùng)
            $txDate = isset($data['transaction_date']) ? \Illuminate\Support\Carbon::parse($data['transaction_date']) : \Illuminate\Support\Carbon::now($timezone);
            $startOfDay = $txDate->copy()->startOfDay();
            $endOfDay = $txDate->copy()->endOfDay();

            $automaticallyLogged = DB::table('transactions')
                ->where('user_id', $userId)
                ->where('wallet_id', $walletId)
                ->where('type', $type)
                ->where('amount', $amount)
                ->where('title', $data['title'])
                ->where('source_type', 'recurring')
                ->whereBetween('transaction_date', [$startOfDay, $endOfDay])
                ->whereNull('deleted_at')
                ->exists();

            if ($automaticallyLogged) {
                throw new \Exception(__('messages.transaction_already_logged_automatically'));
            }

            $transactionId = (string) Str::uuid7();

            // Phân giải tỷ giá hối đoái
            $txCurrency = $data['currency_code'] ?? $walletCurrency;
            if (isset($data['exchange_rate'])) {
                $rate = (float) $data['exchange_rate'];
            } else {
                $rate = $this->exchangeRateService->getRate($txCurrency, $walletCurrency);
            }

            // Quy đổi số tiền sang đơn vị gốc của ví
            $appliedAmount = (float) bcmul((string)$amount, sprintf('%.6f', $rate), 4);

            // Quy đổi số tiền sang tiền tệ hiển thị của user (amount_in_user_currency)
            $userCurrency = DB::table('user_preferences')->where('user_id', $userId)->value('currency') ?? 'VND';
            if ($txCurrency === $userCurrency) {
                $amountInUserCurrency = $amount;
            } else {
                $rateToUserCurrency = $this->exchangeRateService->getRate($txCurrency, $userCurrency);
                $amountInUserCurrency = (float) bcmul((string)$amount, sprintf('%.6f', $rateToUserCurrency), 4);
            }

            // 3. Khóa bảng số dư để cập nhật chống race conditions
            $walletBalance = DB::table('wallet_balances')
                ->where('wallet_id', $walletId)
                ->lockForUpdate()
                ->first();

            if (!$walletBalance) {
                throw new \Exception(__('messages.wallet_balance_not_found'));
            }

            $newBalance = 0;
            if ($type === 'expense') {
                // Kiểm tra xem số dư ví có đủ không (Nếu là tạo thủ công, có thể chặn nếu muốn)
                if (bccomp($walletBalance->available_balance, $appliedAmount, 2) === -1) {
                    throw new \Exception(__('messages.insufficient_balance'));
                }
                $newBalance = bcsub($walletBalance->available_balance, $appliedAmount, 2);
            } else {
                $newBalance = bcadd($walletBalance->available_balance, $appliedAmount, 2);
            }

            // MỚI: Kiểm tra chuyển khoản nội bộ qua giao dịch thủ công
            $isP2P = false;
            $recipientWallet = null;
            $payee = null;
            $sourceType = $data['source_type'] ?? 'manual';
            if (in_array($sourceType, ['manual', 'transfer']) && $type === 'expense' && in_array($wallet->type, ['bank', 'ewallet'])) {
                if (!empty($data['payee_id'])) {
                    $payee = DB::table('saved_payees')
                        ->where('id', $data['payee_id'])
                        ->where('user_id', $userId)
                        ->first();
                    if ($payee && $payee->payee_type === 'internal' && !empty($payee->payee_user_id)) {
                        $isP2P = true;
                        
                        // Find recipient's default bank/ewallet wallet in VND first
                        $recipientWallet = DB::table('wallets')
                            ->where('user_id', $payee->payee_user_id)
                            ->whereIn('type', ['bank', 'ewallet'])
                            ->where('currency_code', 'VND')
                            ->where('is_default_receiving', true)
                            ->whereNull('deleted_at')
                            ->first();

                        if (!$recipientWallet) {
                            // Fall back to recipient's first active bank/ewallet wallet in VND
                            $recipientWallet = DB::table('wallets')
                                ->where('user_id', $payee->payee_user_id)
                                ->whereIn('type', ['bank', 'ewallet'])
                                ->where('currency_code', 'VND')
                                ->whereNull('deleted_at')
                                ->first();
                        }

                        if (!$recipientWallet) {
                            throw new \Exception(__('messages.qr_recipient_no_valid_wallet'));
                        }
                    }
                }
            }

            $transferId = $isP2P ? (string) Str::uuid7() : null;

            // Tự động phân loại danh mục bằng AI nếu category_id trống
            if (empty($categoryId)) {
                $categoryId = $this->autoClassifyCategory($userId, $data['title'] ?? null, $data['notes'] ?? null, $type);
            }

            $txTitle = $data['title'] ?? null;
            if ($isP2P) {
                $txTitle = "Chuyển tiền đến " . ($payee->payee_name ?? 'Người nhận');
            } elseif (empty($txTitle)) {
                if ($categoryId) {
                    $categoryName = DB::table('categories')->where('id', $categoryId)->value('name');
                    $txTitle = $categoryName ?: ($type === 'expense' ? 'Chi tiêu' : 'Thu nhập');
                } else {
                    $txTitle = $type === 'expense' ? 'Chi tiêu' : 'Thu nhập';
                }
            }

            // Tạo giao dịch người gửi (sender transaction)
            $transaction = Transaction::create([
                'id' => $transactionId,
                'user_id' => $userId,
                'wallet_id' => $walletId,
                'category_id' => $categoryId,
                'payee_id' => $data['payee_id'] ?? null,
                'type' => $type,
                'status' => $data['status'] ?? 'completed',
                'amount' => $amount,
                'currency_code' => $txCurrency,
                'exchange_rate' => $rate,
                'amount_in_user_currency' => $amountInUserCurrency,
                'title' => $txTitle,
                'notes' => $data['notes'] ?? null,
                'timezone' => $timezone,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'source_type' => $isP2P ? 'transfer' : ($data['source_type'] ?? 'manual'),
                'source_id' => $isP2P ? $transferId : ($data['source_id'] ?? null)
            ]);

            // Cập nhật số dư ví người gửi
            DB::table('wallet_balances')->where('wallet_id', $walletId)->update([
                'available_balance' => $newBalance,
                'last_transaction_id' => $transactionId,
                'updated_at' => now()
            ]);

            if ($isP2P) {
                $recipientTransactionId = (string) Str::uuid7();

                $recipientCurrency = $recipientWallet->currency_code ?? 'VND';
                $rateToRecipient = $this->exchangeRateService->getRate($txCurrency, $recipientCurrency);
                $recipientAmount = (float) bcmul((string)$amount, sprintf('%.6f', $rateToRecipient), 4);

                // Lock recipient balance
                $recipientBalance = DB::table('wallet_balances')
                    ->where('wallet_id', $recipientWallet->id)
                    ->lockForUpdate()
                    ->first();
                if (!$recipientBalance) {
                    throw new \Exception("Không tìm thấy thông tin số dư ví người nhận.");
                }
                $newRecipientBalance = bcadd($recipientBalance->available_balance, $recipientAmount, 2);
                // Recipient amount in user currency
                $recipientUserCurrency = DB::table('user_preferences')->where('user_id', $payee->payee_user_id)->value('currency') ?? 'VND';
                if ($recipientCurrency === $recipientUserCurrency) {
                    $recipientAmountInUserCurrency = $recipientAmount;
                } else {
                    $rateToRecipientUser = $this->exchangeRateService->getRate($recipientCurrency, $recipientUserCurrency);
                    $recipientAmountInUserCurrency = (float) bcmul((string)$recipientAmount, sprintf('%.6f', $rateToRecipientUser), 4);
                }

                $senderProfile = DB::table('user_profiles')->where('user_id', $userId)->first();
                $senderName = $senderProfile ? $senderProfile->full_name : 'Người gửi';

                $recipientCategoryId = $this->autoClassifyCategory($payee->payee_user_id, "Nhận tiền từ {$senderName}", $data['notes'] ?? null, 'income');

                // Create recipient transaction
                Transaction::create([
                    'id' => $recipientTransactionId,
                    'user_id' => $payee->payee_user_id,
                    'wallet_id' => $recipientWallet->id,
                    'category_id' => $recipientCategoryId,
                    'payee_id' => null,
                    'type' => 'income',
                    'status' => 'completed',
                    'amount' => $recipientAmount,
                    'currency_code' => $recipientCurrency,
                    'exchange_rate' => $rateToRecipient,
                    'amount_in_user_currency' => $recipientAmountInUserCurrency,
                    'title' => "Nhận tiền từ {$senderName}",
                    'notes' => $data['notes'] ?? null,
                    'timezone' => DB::table('user_preferences')->where('user_id', $payee->payee_user_id)->value('timezone') ?? 'Asia/Ho_Chi_Minh',
                    'transaction_date' => $data['transaction_date'] ?? now(),
                    'source_type' => 'transfer',
                    'source_id' => $transferId
                ]);

                // Update recipient balance
                DB::table('wallet_balances')->where('wallet_id', $recipientWallet->id)->update([
                    'available_balance' => $newRecipientBalance,
                    'last_transaction_id' => $recipientTransactionId,
                    'updated_at' => now()
                ]);

                // Create wallet transfer record
                DB::table('wallet_transfers')->insert([
                    'id' => $transferId,
                    'from_wallet_id' => $walletId,
                    'to_wallet_id' => $recipientWallet->id,
                    'amount' => $amount,
                    'expense_transaction_id' => $transactionId,
                    'income_transaction_id' => $recipientTransactionId,
                    'transferred_at' => now(),
                    'timezone' => $timezone,
                    'created_at' => now()
                ]);
            }

            // Gửi thông báo chuyển khoản P2P
            if ($isP2P) {
                try {
                    $recipientUser = \App\Models\User::find($payee->payee_user_id);
                    if ($recipientUser) {
                        $senderProfile = DB::table('user_profiles')->where('user_id', $userId)->first();
                        $senderName = $senderProfile ? $senderProfile->full_name : 'Người gửi';
                        $recipientUser->notify(new \App\Notifications\P2pTransferReceivedNotification(
                            $senderName,
                            $recipientAmount,
                            $recipientCurrency,
                            $data['notes'] ?? null
                        ));
                    }
                } catch (\Throwable $e) {
                    Log::error("Lỗi khi gửi thông báo chuyển tiền P2P: " . $e->getMessage());
                }
            }

            // 5. Xử lý đính kèm nếu có
            $filesToUpload = [];
            if ($attachment) {
                $filesToUpload[] = $attachment;
            }
            if ($attachments) {
                foreach ($attachments as $file) {
                    if ($file instanceof UploadedFile) {
                        $filesToUpload[] = $file;
                    }
                }
            }

            $s3Key = config('filesystems.disks.s3.key');
            $s3Secret = config('filesystems.disks.s3.secret');
            $s3Bucket = config('filesystems.disks.s3.bucket');
            $provider = (!empty($s3Key) && !empty($s3Secret) && !empty($s3Bucket)) ? 's3' : 'local';

            foreach ($filesToUpload as $file) {
                $fileUrl = $this->imageUploadService->uploadToS3($file, 'receipts');
                TransactionAttachment::create([
                    'id' => (string) Str::uuid7(),
                    'transaction_id' => $transactionId,
                    'storage_provider_enum' => $provider,
                    'file_key' => $fileUrl, // Lưu URL trực tiếp vào key để deleteFromS3 phân tích cú pháp
                    'file_url' => $fileUrl,
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_at' => now()
                ]);
            }

            // 6. Ghi log audit
            TransactionAudit::create([
                'transaction_id' => $transactionId,
                'old_data' => null,
                'new_data' => $transaction->toArray(),
                'changed_by' => $userId
            ]);

            // Bắn sự kiện TransactionSaved
            event(new \App\Events\TransactionSaved($transaction));

            return $transaction->load('category', 'wallet', 'attachments');
        });
    }

    /**
     * Sửa giao dịch
     */
    public function updateTransaction(string $id, string $userId, array $data, ?UploadedFile $attachment = null, ?array $attachments = null)
    {
        return DB::transaction(function () use ($id, $userId, $data, $attachment, $attachments) {
            // Lock bản ghi giao dịch chính để tránh race condition
            $transaction = Transaction::where('id', $id)->lockForUpdate()->first();

            if (!$transaction || $transaction->user_id !== $userId) {
                throw new \Exception(__('messages.transaction_not_found_or_unauthorized'));
            }

            // Chặn sửa trực tiếp giao dịch chuyển khoản bị khóa (chuyển khoản nội bộ)
            if ($transaction->is_transfer_locked) {
                throw new \Exception(__('messages.cannot_edit_transfer_directly'));
            }

            // Với giao dịch chuyển khoản bằng QR (P2P hoặc VietQR)
            if ($transaction->source_type === 'transfer') {
                // Chỉ cho phép sửa đổi category_id, title, notes, attachments
                if (
                    (isset($data['wallet_id']) && $data['wallet_id'] !== $transaction->wallet_id) ||
                    (isset($data['amount']) && (float)$data['amount'] !== (float)$transaction->amount) ||
                    (isset($data['type']) && $data['type'] !== $transaction->type) ||
                    (isset($data['currency_code']) && $data['currency_code'] !== $transaction->currency_code)
                ) {
                    throw new \Exception(__('messages.qr_transfer_edit_restricted'));
                }
            }

            $oldData = $transaction->toArray();
            $oldIsSplit = (bool) $transaction->is_split;
            $newIsSplit = isset($data['splits']) && is_array($data['splits']) && count($data['splits']) > 1;

            $newType = $data['type'] ?? $transaction->type;
            if ($newIsSplit && $newType !== 'expense') {
                throw new \Exception("Chỉ cho phép thanh toán kết hợp nhiều ví đối với giao dịch chi tiêu (expense).");
            }

            $userCurrency = DB::table('user_preferences')->where('user_id', $userId)->value('currency') ?? 'VND';

            // --- BƯỚC 1: HOÀN TÁC SỐ DƯ CŨ (ROLLBACK) ---
            if ($oldIsSplit) {
                $oldSplits = DB::table('transaction_splits')->where('transaction_id', $id)->get();
                $sortedOldSplits = $oldSplits->sortBy('wallet_id');
                foreach ($sortedOldSplits as $oldSplit) {
                    $walletBal = DB::table('wallet_balances')
                        ->where('wallet_id', $oldSplit->wallet_id)
                        ->lockForUpdate()
                        ->first();
                    if ($walletBal) {
                        $revertedVal = bcadd($walletBal->available_balance, $oldSplit->amount, 2);
                        DB::table('wallet_balances')->where('wallet_id', $oldSplit->wallet_id)->update([
                            'available_balance' => $revertedVal,
                            'updated_at' => now()
                        ]);
                    }
                }
                DB::table('transaction_splits')->where('transaction_id', $id)->delete();
            } else {
                $oldWalletId = $transaction->wallet_id;
                $oldWallet = DB::table('wallets')->where('id', $oldWalletId)->first();
                $oldWalletCurrency = $oldWallet->currency_code ?? 'VND';
                $oldRate = (float) ($transaction->exchange_rate ?? 1.0);
                $oldAppliedAmount = (float) bcmul((string)$transaction->amount, sprintf('%.6f', $oldRate), 4);

                $oldWalletBalance = DB::table('wallet_balances')
                    ->where('wallet_id', $oldWalletId)
                    ->lockForUpdate()
                    ->first();

                if (!$oldWalletBalance) {
                    throw new \Exception(__('messages.wallet_balance_not_found'));
                }

                $revertedBalance = 0;
                if ($transaction->type === 'expense') {
                    $revertedBalance = bcadd($oldWalletBalance->available_balance, $oldAppliedAmount, 2);
                } else {
                    $revertedBalance = bcsub($oldWalletBalance->available_balance, $oldAppliedAmount, 2);
                }

                DB::table('wallet_balances')->where('wallet_id', $oldWalletId)->update([
                    'available_balance' => $revertedBalance,
                    'updated_at' => now()
                ]);
            }

            // --- BƯỚC 2: ÁP DỤNG SỐ DƯ MỚI (APPLY) ---
            $newAmountInUserCurrency = 0.0;
            $newCurrency = $data['currency_code'] ?? $transaction->currency_code;

            if ($newIsSplit) {
                $splitsData = $data['splits'];
                // Kiểm tra trùng ví
                $walletIdsInSplits = array_column($splitsData, 'wallet_id');
                if (count($walletIdsInSplits) !== count(array_unique($walletIdsInSplits))) {
                    throw new \Exception("Không được chọn trùng lặp ví trong cùng một giao dịch.");
                }

                // Sắp xếp ví theo ID tăng dần chống Deadlock
                usort($splitsData, function ($a, $b) {
                    return strcmp($a['wallet_id'], $b['wallet_id']);
                });

                $totalSplitUserAmount = 0.0;
                $preparedSplits = [];
                $walletBalancesToUpdate = [];

                foreach ($splitsData as $splitItem) {
                    $splitWalletId = $splitItem['wallet_id'];
                    $splitAmount = (float) $splitItem['amount'];

                    $splitWallet = DB::table('wallets')
                        ->where('id', $splitWalletId)
                        ->where('user_id', $userId)
                        ->whereNull('deleted_at')
                        ->first();

                    if (!$splitWallet) {
                        throw new \Exception("Không tìm thấy ví hoặc bạn không có quyền sử dụng ví này.");
                    }

                    $splitWalletCurrency = $splitWallet->currency_code ?? 'VND';
                    $splitRate = $this->exchangeRateService->getRate($splitWalletCurrency, $userCurrency);
                    $splitAmountInUserCurrency = (float) bcmul((string)$splitAmount, sprintf('%.6f', $splitRate), 4);
                    
                    $totalSplitUserAmount += $splitAmountInUserCurrency;

                    $splitWalletBalance = DB::table('wallet_balances')
                        ->where('wallet_id', $splitWalletId)
                        ->lockForUpdate()
                        ->first();

                    if (!$splitWalletBalance) {
                        throw new \Exception("Không tìm thấy số dư ví.");
                    }

                    if (bccomp($splitWalletBalance->available_balance, (string)$splitAmount, 2) === -1) {
                        throw new \Exception("Số dư Ví '{$splitWallet->name}' không đủ để thực hiện giao dịch.");
                    }

                    $newSplitBalance = (float) bcsub($splitWalletBalance->available_balance, (string)$splitAmount, 2);

                    $preparedSplits[] = [
                        'wallet_id' => $splitWalletId,
                        'amount' => $splitAmount,
                        'amount_in_user_currency' => $splitAmountInUserCurrency,
                        'exchange_rate' => $splitRate,
                    ];

                    $walletBalancesToUpdate[] = [
                        'wallet_id' => $splitWalletId,
                        'new_balance' => $newSplitBalance,
                    ];
                }

                // Validate tổng tiền giao dịch chính
                $amount = (float) (isset($data['amount']) ? $data['amount'] : $transaction->amount);
                if ($newCurrency === $userCurrency) {
                    $newAmountInUserCurrency = $amount;
                } else {
                    $rateToUserCurrency = $this->exchangeRateService->getRate($newCurrency, $userCurrency);
                    $newAmountInUserCurrency = (float) bcmul((string)$amount, sprintf('%.6f', $rateToUserCurrency), 4);
                }

                // Validate sai số 0.02
                if (abs($totalSplitUserAmount - $newAmountInUserCurrency) > 0.02) {
                    throw new \Exception("Tổng số tiền phân tách của các ví (" . number_format($totalSplitUserAmount, 2) . " " . $userCurrency . ") không khớp với số tiền giao dịch chính (" . number_format($newAmountInUserCurrency, 2) . " " . $userCurrency . "). Sai lệch tối đa cho phép là 0.02.");
                }

                // Ghi vào bảng splits
                foreach ($preparedSplits as $prepSplit) {
                    DB::table('transaction_splits')->insert([
                        'id' => (string) Str::uuid(),
                        'transaction_id' => $id,
                        'wallet_id' => $prepSplit['wallet_id'],
                        'amount' => $prepSplit['amount'],
                        'amount_in_user_currency' => $prepSplit['amount_in_user_currency'],
                        'exchange_rate' => $prepSplit['exchange_rate'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Cập nhật số dư
                foreach ($walletBalancesToUpdate as $wb) {
                    DB::table('wallet_balances')->where('wallet_id', $wb['wallet_id'])->update([
                        'available_balance' => $wb['new_balance'],
                        'last_transaction_id' => $id,
                        'updated_at' => now()
                    ]);
                }

                // Cập nhật giao dịch chính
                $transaction->update([
                    'wallet_id' => null,
                    'category_id' => $data['category_id'] ?? $transaction->category_id,
                    'type' => 'expense',
                    'amount' => null,
                    'amount_in_user_currency' => $newAmountInUserCurrency,
                    'title' => $data['title'] ?? $transaction->title,
                    'notes' => $data['notes'] ?? $transaction->notes,
                    'transaction_date' => $data['transaction_date'] ?? $transaction->transaction_date,
                    'currency_code' => $newCurrency,
                    'exchange_rate' => $data['exchange_rate'] ?? 1.0,
                    'timezone' => $data['timezone'] ?? $transaction->timezone,
                    'status' => $data['status'] ?? $transaction->status,
                    'is_split' => true,
                ]);

            } else {
                $newWalletId = $data['wallet_id'] ?? $transaction->wallet_id;
                $newWallet = DB::table('wallets')->where('id', $newWalletId)->first();
                $newWalletCurrency = $newWallet->currency_code ?? 'VND';

                $newAmount = isset($data['amount']) ? (float)$data['amount'] : (float)$transaction->amount;

                if (isset($data['exchange_rate'])) {
                    $newRate = (float) $data['exchange_rate'];
                } else {
                    $newRate = $this->exchangeRateService->getRate($newCurrency, $newWalletCurrency);
                }
                $newAppliedAmount = (float) bcmul((string)$newAmount, sprintf('%.6f', $newRate), 4);

                if ($newCurrency === $userCurrency) {
                    $newAmountInUserCurrency = $newAmount;
                } else {
                    $rateToUserCurrency = $this->exchangeRateService->getRate($newCurrency, $userCurrency);
                    $newAmountInUserCurrency = (float) bcmul((string)$newAmount, sprintf('%.6f', $rateToUserCurrency), 4);
                }

                $newWalletBalance = DB::table('wallet_balances')
                    ->where('wallet_id', $newWalletId)
                    ->lockForUpdate()
                    ->first();

                if (!$newWalletBalance) {
                    throw new \Exception(__('messages.wallet_balance_not_found'));
                }

                $appliedBalance = 0;
                if ($newType === 'expense') {
                    if (bccomp($newWalletBalance->available_balance, $newAppliedAmount, 2) === -1) {
                        throw new \Exception(__('messages.insufficient_balance'));
                    }
                    $appliedBalance = bcsub($newWalletBalance->available_balance, $newAppliedAmount, 2);
                } else {
                    $appliedBalance = bcadd($newWalletBalance->available_balance, $newAppliedAmount, 2);
                }

                DB::table('wallet_balances')->where('wallet_id', $newWalletId)->update([
                    'available_balance' => $appliedBalance,
                    'last_transaction_id' => $id,
                    'updated_at' => now()
                ]);

                $transaction->update([
                    'wallet_id' => $newWalletId,
                    'category_id' => $data['category_id'] ?? $transaction->category_id,
                    'type' => $newType,
                    'amount' => $newAmount,
                    'amount_in_user_currency' => $newAmountInUserCurrency,
                    'title' => $data['title'] ?? $transaction->title,
                    'notes' => $data['notes'] ?? $transaction->notes,
                    'transaction_date' => $data['transaction_date'] ?? $transaction->transaction_date,
                    'currency_code' => $newCurrency,
                    'exchange_rate' => $newRate,
                    'timezone' => $data['timezone'] ?? $transaction->timezone,
                    'status' => $data['status'] ?? $transaction->status,
                    'is_split' => false,
                ]);
            }

            // --- BƯỚC 3: XỬ LÝ ĐÍNH KÈM & AUDIT LOG ---
            $filesToUpload = [];
            if ($attachment) {
                $filesToUpload[] = $attachment;
            }
            if ($attachments) {
                foreach ($attachments as $file) {
                    if ($file instanceof UploadedFile) {
                        $filesToUpload[] = $file;
                    }
                }
            }

            if (!empty($filesToUpload)) {
                $oldAttachments = TransactionAttachment::where('transaction_id', $id)->get();
                foreach ($oldAttachments as $oldAttach) {
                    $this->imageUploadService->deleteFromS3($oldAttach->file_url);
                    $oldAttach->delete();
                }

                $s3Key = config('filesystems.disks.s3.key');
                $s3Secret = config('filesystems.disks.s3.secret');
                $s3Bucket = config('filesystems.disks.s3.bucket');
                $provider = (!empty($s3Key) && !empty($s3Secret) && !empty($s3Bucket)) ? 's3' : 'local';

                foreach ($filesToUpload as $file) {
                    $fileUrl = $this->imageUploadService->uploadToS3($file, 'receipts');
                    TransactionAttachment::create([
                        'id' => (string) Str::uuid7(),
                        'transaction_id' => $id,
                        'storage_provider_enum' => $provider,
                        'file_key' => $fileUrl,
                        'file_url' => $fileUrl,
                        'mime_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'uploaded_at' => now()
                    ]);
                }
            }

            TransactionAudit::create([
                'transaction_id' => $id,
                'old_data' => $oldData,
                'new_data' => $transaction->fresh()->toArray(),
                'changed_by' => $userId
            ]);

            event(new \App\Events\TransactionSaved($transaction, $oldData));

            return $transaction->load('category', 'wallet', 'attachments', 'splits.wallet');
        });
    }

    /**
     * Xóa giao dịch (Có xử lý đặc biệt cho chuyển khoản để xóa cả 2 giao dịch đối ứng)
     */
    public function deleteTransaction(string $id, string $userId)
    {
        return DB::transaction(function () use ($id, $userId) {
            $transaction = Transaction::where('id', $id)->lockForUpdate()->first();

            if (!$transaction || $transaction->user_id !== $userId) {
                throw new \Exception(__('messages.transaction_not_found_or_unauthorized'));
            }

            $oldData = $transaction->toArray();

            // LƯU Ý ĐẶC BIỆT: Nếu là chuyển khoản nội bộ
            if ($transaction->source_type === 'transfer') {
                $transfer = DB::table('wallet_transfers')
                    ->where('expense_transaction_id', $id)
                    ->orWhere('income_transaction_id', $id)
                    ->first();

                if ($transfer) {
                    $expenseTx = Transaction::find($transfer->expense_transaction_id);
                    $incomeTx = Transaction::find($transfer->income_transaction_id);

                    // Hoàn tác số dư cả 2 ví
                    if ($expenseTx) {
                        $exWalletBalance = DB::table('wallet_balances')
                            ->where('wallet_id', $expenseTx->wallet_id)
                            ->lockForUpdate()
                            ->first();

                        if ($exWalletBalance) {
                            $appliedExAmount = (float) bcmul((string)$expenseTx->amount, sprintf('%.6f', $expenseTx->exchange_rate ?? 1.000000), 4);
                            DB::table('wallet_balances')->where('wallet_id', $expenseTx->wallet_id)->update([
                                'available_balance' => bcadd($exWalletBalance->available_balance, $appliedExAmount, 2),
                                'updated_at' => now()
                            ]);
                        }

                        // Xóa các file đính kèm của expenseTx
                        $attachments = TransactionAttachment::where('transaction_id', $expenseTx->id)->get();
                        foreach ($attachments as $attach) {
                            $this->imageUploadService->deleteFromS3($attach->file_url);
                            $attach->delete();
                        }

                        TransactionAudit::create([
                            'transaction_id' => $expenseTx->id,
                            'old_data' => $expenseTx->toArray(),
                            'new_data' => ['deleted' => true],
                            'changed_by' => $userId
                        ]);

                        $expenseTx->delete();
                    }

                    if ($incomeTx) {
                        $inWalletBalance = DB::table('wallet_balances')
                            ->where('wallet_id', $incomeTx->wallet_id)
                            ->lockForUpdate()
                            ->first();

                        if ($inWalletBalance) {
                            $appliedInAmount = (float) bcmul((string)$incomeTx->amount, sprintf('%.6f', $incomeTx->exchange_rate ?? 1.000000), 4);
                            DB::table('wallet_balances')->where('wallet_id', $incomeTx->wallet_id)->update([
                                'available_balance' => bcsub($inWalletBalance->available_balance, $appliedInAmount, 2),
                                'updated_at' => now()
                            ]);
                        }

                        // Xóa các file đính kèm của incomeTx
                        $attachments = TransactionAttachment::where('transaction_id', $incomeTx->id)->get();
                        foreach ($attachments as $attach) {
                            $this->imageUploadService->deleteFromS3($attach->file_url);
                            $attach->delete();
                        }

                        TransactionAudit::create([
                            'transaction_id' => $incomeTx->id,
                            'old_data' => $incomeTx->toArray(),
                            'new_data' => ['deleted' => true],
                            'changed_by' => $userId
                        ]);

                        $incomeTx->delete();
                    }

                    // Xóa bản ghi trong wallet_transfers
                    DB::table('wallet_transfers')->where('id', $transfer->id)->delete();

                    // Bắn sự kiện TransactionSaved cho cả 2 giao dịch bị xóa
                    if ($expenseTx) {
                        event(new \App\Events\TransactionSaved($expenseTx, null, true));
                    }
                    if ($incomeTx) {
                        event(new \App\Events\TransactionSaved($incomeTx, null, true));
                    }

                    return true;
                }
            }

            // GIAO DỊCH THƯỜNG (Manual hoặc Recurring) hoặc Split
            if ($transaction->is_split) {
                $splits = DB::table('transaction_splits')->where('transaction_id', $id)->get();
                $sortedSplits = $splits->sortBy('wallet_id');
                foreach ($sortedSplits as $split) {
                    $walletBalance = DB::table('wallet_balances')
                        ->where('wallet_id', $split->wallet_id)
                        ->lockForUpdate()
                        ->first();
                    if ($walletBalance) {
                        $revertedBalance = bcadd($walletBalance->available_balance, $split->amount, 2);
                        DB::table('wallet_balances')->where('wallet_id', $split->wallet_id)->update([
                            'available_balance' => $revertedBalance,
                            'updated_at' => now()
                        ]);
                    }
                }
            } else {
                $walletBalance = DB::table('wallet_balances')
                    ->where('wallet_id', $transaction->wallet_id)
                    ->lockForUpdate()
                    ->first();

                if ($walletBalance) {
                    // Quy đổi số tiền cần hoàn trả bằng tỷ giá đã lưu của chính giao dịch đó
                    $appliedAmount = (float) bcmul((string)$transaction->amount, sprintf('%.6f', $transaction->exchange_rate ?? 1.000000), 4);

                    $revertedBalance = 0;
                    if ($transaction->type === 'expense') {
                        $revertedBalance = bcadd($walletBalance->available_balance, $appliedAmount, 2);
                    } else {
                        $revertedBalance = bcsub($walletBalance->available_balance, $appliedAmount, 2);
                    }

                    DB::table('wallet_balances')->where('wallet_id', $transaction->wallet_id)->update([
                        'available_balance' => $revertedBalance,
                        'updated_at' => now()
                    ]);
                }
            }

            // Xóa file đính kèm khỏi S3/local
            $attachments = TransactionAttachment::where('transaction_id', $id)->get();
            foreach ($attachments as $attach) {
                $this->imageUploadService->deleteFromS3($attach->file_url);
                $attach->delete();
            }

            // Ghi log audit
            TransactionAudit::create([
                'transaction_id' => $id,
                'old_data' => $oldData,
                'new_data' => ['deleted' => true],
                'changed_by' => $userId
            ]);

            // Soft delete giao dịch
            $transaction->delete();

            // Bắn sự kiện TransactionSaved
            event(new \App\Events\TransactionSaved($transaction, $oldData, true));

            return true;
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

            $response = \Illuminate\Support\Facades\Http::timeout(5)->post($url, $payload);

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
            Log::warning('AI Auto-classification failed in TransactionService: ' . $e->getMessage());
            return null;
        }
    }
}
