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
        $transaction = Transaction::with(['category', 'wallet', 'attachments'])->find($id);

        if (!$transaction || $transaction->user_id !== $userId) {
            throw new \Exception(__('messages.transaction_not_found_or_unauthorized'));
        }

        return $transaction;
    }

    /**
     * Tạo giao dịch thủ công kèm đính kèm
     */
    public function createTransaction(string $userId, array $data, ?UploadedFile $attachment = null)
    {
        return DB::transaction(function () use ($userId, $data, $attachment) {
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

            $transactionId = (string) Str::uuid7();

            // Phân giải tỷ giá hối đoái
            $txCurrency = $data['currency_code'] ?? $walletCurrency;
            if (isset($data['exchange_rate'])) {
                $rate = (float) $data['exchange_rate'];
            } else {
                $rate = $this->exchangeRateService->getRate($txCurrency, $walletCurrency);
            }

            // Quy đổi số tiền sang đơn vị gốc của ví
            $appliedAmount = (float) bcmul($amount, $rate, 4);

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

            // 4. Tạo giao dịch
            $transaction = Transaction::create([
                'id' => $transactionId,
                'user_id' => $userId,
                'wallet_id' => $walletId,
                'category_id' => $categoryId,
                'type' => $type,
                'status' => $data['status'] ?? 'completed',
                'amount' => $amount,
                'currency_code' => $txCurrency,
                'exchange_rate' => $rate,
                'title' => $data['title'],
                'notes' => $data['notes'] ?? null,
                'timezone' => $timezone,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'source_type' => $data['source_type'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null
            ]);

            // Cập nhật số dư ví
            DB::table('wallet_balances')->where('wallet_id', $walletId)->update([
                'available_balance' => $newBalance,
                'last_transaction_id' => $transactionId,
                'updated_at' => now()
            ]);

            // 5. Xử lý đính kèm nếu có
            if ($attachment) {
                $fileUrl = $this->imageUploadService->uploadToS3($attachment, 'receipts');

                $s3Key = config('filesystems.disks.s3.key');
                $s3Secret = config('filesystems.disks.s3.secret');
                $s3Bucket = config('filesystems.disks.s3.bucket');
                $provider = (!empty($s3Key) && !empty($s3Secret) && !empty($s3Bucket)) ? 's3' : 'local';

                TransactionAttachment::create([
                    'id' => (string) Str::uuid7(),
                    'transaction_id' => $transactionId,
                    'storage_provider_enum' => $provider,
                    'file_key' => $fileUrl, // Lưu URL trực tiếp vào key để deleteFromS3 phân tích cú pháp
                    'file_url' => $fileUrl,
                    'mime_type' => $attachment->getClientMimeType(),
                    'file_size' => $attachment->getSize(),
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

            return $transaction->load('category', 'wallet', 'attachments');
        });
    }

    /**
     * Sửa giao dịch
     */
    public function updateTransaction(string $id, string $userId, array $data, ?UploadedFile $attachment = null)
    {
        return DB::transaction(function () use ($id, $userId, $data, $attachment) {
            $transaction = Transaction::find($id);

            if (!$transaction || $transaction->user_id !== $userId) {
                throw new \Exception(__('messages.transaction_not_found_or_unauthorized'));
            }

            // Ngăn chặn sửa trực tiếp giao dịch chuyển khoản (source_type = 'transfer')
            if ($transaction->source_type === 'transfer') {
                throw new \Exception(__('messages.cannot_edit_transfer_directly'));
            }

            $oldData = $transaction->toArray();

            $oldWalletId = $transaction->wallet_id;
            $newWalletId = $data['wallet_id'] ?? $oldWalletId;

            $oldAmount = (float) $transaction->amount;
            $newAmount = isset($data['amount']) ? (float) $data['amount'] : $oldAmount;

            $oldType = $transaction->type;
            $newType = $data['type'] ?? $oldType;

            // Lấy currency của new wallet và old wallet
            $oldWallet = DB::table('wallets')->where('id', $oldWalletId)->first();
            $oldWalletCurrency = $oldWallet->currency_code ?? 'VND';

            $newWallet = DB::table('wallets')->where('id', $newWalletId)->first();
            $newWalletCurrency = $newWallet->currency_code ?? 'VND';

            // Tính số tiền quy đổi cũ
            $oldRate = (float) ($transaction->exchange_rate ?? 1.000000);
            $oldAppliedAmount = (float) bcmul($oldAmount, $oldRate, 4);

            // Tính số tiền quy đổi mới
            $newCurrency = $data['currency_code'] ?? $transaction->currency_code;
            if (isset($data['exchange_rate'])) {
                $newRate = (float) $data['exchange_rate'];
            } elseif (isset($data['currency_code']) && $data['currency_code'] !== $transaction->currency_code) {
                // Nếu thay đổi loại tiền nhưng không truyền tỷ giá, tự động fetch tỷ giá mới
                $newRate = $this->exchangeRateService->getRate($newCurrency, $newWalletCurrency);
            } elseif ($newWalletCurrency !== $oldWalletCurrency) {
                // Nếu thay đổi ví và ví mới có tiền tệ khác ví cũ, tự động quy đổi lại theo tiền tệ ví mới
                $newRate = $this->exchangeRateService->getRate($newCurrency, $newWalletCurrency);
            } else {
                $newRate = (float) ($transaction->exchange_rate ?? 1.000000);
            }
            $newAppliedAmount = (float) bcmul($newAmount, $newRate, 4);

            // 1. Kiểm tra ví mới nếu thay đổi ví
            if ($newWalletId !== $oldWalletId) {
                $walletExists = DB::table('wallets')
                    ->where('id', $newWalletId)
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at')
                    ->exists();
                if (!$walletExists) {
                    throw new \Exception(__('messages.wallet_not_found_or_unauthorized'));
                }
            }

            // 2. Kiểm tra danh mục mới nếu thay đổi danh mục
            if (isset($data['category_id']) && $data['category_id'] !== $transaction->category_id) {
                $categoryExists = DB::table('categories')
                    ->where('id', $data['category_id'])
                    ->where(function ($q) use ($userId) {
                        $q->where('user_id', $userId)
                          ->orWhere('is_default', true);
                    })
                    ->whereNull('deleted_at')
                    ->exists();
                if (!$categoryExists) {
                    throw new \Exception(__('messages.category_not_found_or_unauthorized'));
                }
            }

            // 3. Cập nhật lại số dư ví (nếu thay đổi số tiền, tỷ giá, tiền tệ, loại giao dịch hoặc ví)
            if ($oldWalletId !== $newWalletId || $oldAppliedAmount !== $newAppliedAmount || $oldType !== $newType) {
                // Hoàn tác số dư ở ví cũ trước (sử dụng số tiền quy đổi cũ)
                $oldWalletBalance = DB::table('wallet_balances')
                    ->where('wallet_id', $oldWalletId)
                    ->lockForUpdate()
                    ->first();

                if (!$oldWalletBalance) {
                    throw new \Exception(__('messages.wallet_balance_not_found'));
                }

                $revertedBalance = 0;
                if ($oldType === 'expense') {
                    $revertedBalance = bcadd($oldWalletBalance->available_balance, $oldAppliedAmount, 2);
                } else {
                    $revertedBalance = bcsub($oldWalletBalance->available_balance, $oldAppliedAmount, 2);
                }

                DB::table('wallet_balances')->where('wallet_id', $oldWalletId)->update([
                    'available_balance' => $revertedBalance,
                    'updated_at' => now()
                ]);

                // Áp dụng số dư mới ở ví mới (sử dụng số tiền quy đổi mới)
                $newWalletBalance = DB::table('wallet_balances')
                    ->where('wallet_id', $newWalletId)
                    ->lockForUpdate()
                    ->first();

                if (!$newWalletBalance) {
                    throw new \Exception(__('messages.wallet_balance_not_found'));
                }

                $appliedBalance = 0;
                if ($newType === 'expense') {
                    // Kiểm tra xem số dư ví mới sau khi hoàn tác ví cũ có đủ không
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
            }

            // 4. Cập nhật các trường thông tin giao dịch
            $transaction->update([
                'wallet_id' => $newWalletId,
                'category_id' => $data['category_id'] ?? $transaction->category_id,
                'type' => $newType,
                'amount' => $newAmount,
                'title' => $data['title'] ?? $transaction->title,
                'notes' => $data['notes'] ?? $transaction->notes,
                'transaction_date' => $data['transaction_date'] ?? $transaction->transaction_date,
                'currency_code' => $newCurrency,
                'exchange_rate' => $newRate,
                'timezone' => $data['timezone'] ?? $transaction->timezone,
                'status' => $data['status'] ?? $transaction->status
            ]);

            // 5. Cập nhật đính kèm
            if ($attachment) {
                // Xóa đính kèm cũ (nếu có)
                $oldAttachments = TransactionAttachment::where('transaction_id', $id)->get();
                foreach ($oldAttachments as $oldAttach) {
                    $this->imageUploadService->deleteFromS3($oldAttach->file_url);
                    $oldAttach->delete();
                }

                // Upload đính kèm mới
                $fileUrl = $this->imageUploadService->uploadToS3($attachment, 'receipts');

                $s3Key = config('filesystems.disks.s3.key');
                $s3Secret = config('filesystems.disks.s3.secret');
                $s3Bucket = config('filesystems.disks.s3.bucket');
                $provider = (!empty($s3Key) && !empty($s3Secret) && !empty($s3Bucket)) ? 's3' : 'local';

                TransactionAttachment::create([
                    'id' => (string) Str::uuid7(),
                    'transaction_id' => $id,
                    'storage_provider_enum' => $provider,
                    'file_key' => $fileUrl,
                    'file_url' => $fileUrl,
                    'mime_type' => $attachment->getClientMimeType(),
                    'file_size' => $attachment->getSize(),
                    'uploaded_at' => now()
                ]);
            }

            // 6. Ghi log audit
            TransactionAudit::create([
                'transaction_id' => $id,
                'old_data' => $oldData,
                'new_data' => $transaction->fresh()->toArray(),
                'changed_by' => $userId
            ]);

            return $transaction->load('category', 'wallet', 'attachments');
        });
    }

    /**
     * Xóa giao dịch (Có xử lý đặc biệt cho chuyển khoản để xóa cả 2 giao dịch đối ứng)
     */
    public function deleteTransaction(string $id, string $userId)
    {
        return DB::transaction(function () use ($id, $userId) {
            $transaction = Transaction::find($id);

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
                            $appliedExAmount = (float) bcmul($expenseTx->amount, $expenseTx->exchange_rate ?? 1.000000, 4);
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
                            $appliedInAmount = (float) bcmul($incomeTx->amount, $incomeTx->exchange_rate ?? 1.000000, 4);
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

                    return true;
                }
            }

            // GIAO DỊCH THƯỜNG (Manual hoặc Recurring)
            $walletBalance = DB::table('wallet_balances')
                ->where('wallet_id', $transaction->wallet_id)
                ->lockForUpdate()
                ->first();

            if ($walletBalance) {
                // Quy đổi số tiền cần hoàn trả bằng tỷ giá đã lưu của chính giao dịch đó
                $appliedAmount = (float) bcmul($transaction->amount, $transaction->exchange_rate ?? 1.000000, 4);

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

            return true;
        });
    }
}
