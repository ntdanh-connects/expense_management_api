<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\ImportJob;
use App\Services\TransactionService;

class ImportTransactionsJob implements ShouldQueue
{
    use Queueable;

    protected $userId;
    protected $importId;
    protected $filePath;

    /**
     * Create a new job instance.
     */
    public function __construct(string $userId, string $importId, string $filePath)
    {
        $this->userId = $userId;
        $this->importId = $importId;
        $this->filePath = $filePath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Bắt đầu xử lý nhập giao dịch từ file cho user: {$this->userId}, import_id: {$this->importId}");

        // 1. Cập nhật trạng thái sang processing sử dụng Eloquent
        ImportJob::where('id', $this->importId)->update([
            'status' => 'processing',
        ]);

        try {
            $user = User::find($this->userId);
            if (!$user) {
                throw new \Exception("Không tìm thấy người dùng.");
            }

            // 2. Đọc file CSV từ Storage
            $diskName = config('filesystems.default') === 's3' ? 's3' : 'public';
            $disk = Storage::disk($diskName);

            if (!$disk->exists($this->filePath)) {
                throw new \Exception("File không tồn tại trên hệ thống lưu trữ.");
            }

            $csvContent = $disk->get($this->filePath);

            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $csvContent);
            rewind($stream);

            // Skip UTF-8 BOM nếu có
            $bom = fread($stream, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($stream);
            }

            // Đọc dòng tiêu đề (Header)
            $header = fgetcsv($stream);
            if (!$header) {
                throw new \Exception("File CSV trống hoặc không đúng định dạng.");
            }

            // Map chỉ mục của các cột dựa trên tên tiếng Việt hoặc tiếng Anh
            $colMap = $this->mapHeaders($header);

            // Bắt buộc phải có các cột tối thiểu: ngày, loại, số tiền, ví, tiêu đề
            if ($colMap['transaction_date'] === null || $colMap['type'] === null || 
                $colMap['amount'] === null || $colMap['wallet'] === null || $colMap['title'] === null) {
                throw new \Exception("File CSV thiếu các cột bắt buộc: Ngày, Loại, Số tiền, Ví, Tiêu đề.");
            }

            $successRows = 0;
            $failedRows = 0;
            $totalRows = 0;
            $errorRows = [];

            $txService = app(TransactionService::class);

            // Cache danh sách ví và danh mục của user để tăng tốc độ truy vấn
            $walletsCache = DB::table('wallets')
                ->where('user_id', $this->userId)
                ->whereNull('deleted_at')
                ->pluck('id', 'name')
                ->toArray();

            $categoriesCache = DB::table('categories')
                ->where(function ($q) {
                    $q->where('user_id', $this->userId)
                      ->orWhere('is_default', true);
                })
                ->whereNull('deleted_at')
                ->pluck('id', 'name')
                ->toArray();

            // 3. Đọc dữ liệu từng dòng
            while (($row = fgetcsv($stream)) !== false) {
                // Bỏ qua dòng trống
                if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                    continue;
                }

                $totalRows++;
                
                try {
                    // Phân giải dữ liệu dòng
                    $rawDate = $this->getVal($row, $colMap['transaction_date']);
                    $rawType = $this->getVal($row, $colMap['type']);
                    $rawAmount = $this->getVal($row, $colMap['amount']);
                    $rawWallet = $this->getVal($row, $colMap['wallet']);
                    $rawCategory = $this->getVal($row, $colMap['category']);
                    $rawTitle = $this->getVal($row, $colMap['title']);
                    $rawNotes = $this->getVal($row, $colMap['notes']);
                    $rawCurrency = $this->getVal($row, $colMap['currency_code']) ?? 'VND';

                    // Validate cơ bản
                    if (empty($rawDate) || empty($rawType) || empty($rawAmount) || empty($rawWallet) || empty($rawTitle)) {
                        throw new \Exception("Dòng dữ liệu bị thiếu trường bắt buộc.");
                    }

                    // Parse Date
                    try {
                        $date = Carbon::parse($rawDate)->toDateTimeString();
                    } catch (\Throwable $e) {
                        throw new \Exception("Định dạng ngày không hợp lệ: '{$rawDate}'");
                    }

                    // Parse Type
                    $type = strtolower(trim($rawType));
                    if (in_array($type, ['chi tiêu', 'expense', 'chi'])) {
                        $type = 'expense';
                    } elseif (in_array($type, ['thu nhập', 'income', 'thu'])) {
                        $type = 'income';
                    } else {
                        throw new \Exception("Loại giao dịch không hợp lệ: '{$rawType}'");
                    }

                    // Parse Amount
                    $amount = (float) filter_var($rawAmount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    if ($amount <= 0) {
                        throw new \Exception("Số tiền phải lớn hơn 0.");
                    }

                    // Tìm ví ID theo tên hoặc ID trực tiếp
                    $walletId = null;
                    if (isset($walletsCache[$rawWallet])) {
                        $walletId = $walletsCache[$rawWallet];
                    } elseif (Str::isUuid($rawWallet) && DB::table('wallets')->where('id', $rawWallet)->where('user_id', $this->userId)->exists()) {
                        $walletId = $rawWallet;
                    } else {
                        throw new \Exception("Không tìm thấy ví có tên hoặc ID: '{$rawWallet}'");
                    }

                    // Tìm danh mục ID theo tên hoặc ID trực tiếp
                    $categoryId = null;
                    if (!empty($rawCategory)) {
                        if (isset($categoriesCache[$rawCategory])) {
                            $categoryId = $categoriesCache[$rawCategory];
                        } elseif (Str::isUuid($rawCategory) && DB::table('categories')->where('id', $rawCategory)->exists()) {
                            $categoryId = $rawCategory;
                        } else {
                            throw new \Exception("Không tìm thấy danh mục có tên hoặc ID: '{$rawCategory}'");
                        }
                    }

                    // Gọi Service tạo giao dịch (Hàm này có lock balance ví và bắn sự kiện để cập nhật ngân sách/thống kê!)
                    $txService->createTransaction($this->userId, [
                        'wallet_id' => $walletId,
                        'category_id' => $categoryId,
                        'type' => $type,
                        'amount' => $amount,
                        'currency_code' => $rawCurrency,
                        'title' => $rawTitle,
                        'notes' => $rawNotes,
                        'transaction_date' => $date,
                        'source_type' => 'import',
                        'source_id' => $this->importId
                    ]);

                    $successRows++;

                } catch (\Throwable $e) {
                    $failedRows++;
                    // Ghi nhận dòng lỗi kèm lý do lỗi
                    $row[] = $e->getMessage();
                    $errorRows[] = $row;
                }
            }

            fclose($stream);

            // 4. Tạo file báo cáo lỗi nếu có dòng lỗi
            $errorFileUrl = null;
            if ($failedRows > 0) {
                $errStream = fopen('php://temp', 'r+');
                fprintf($errStream, chr(0xEF).chr(0xBB).chr(0xBF));

                // Viết lại header + cột lý do lỗi
                $errHeader = $header;
                $errHeader[] = 'Lý Do Lỗi';
                fputcsv($errStream, $errHeader);

                foreach ($errorRows as $errRow) {
                    fputcsv($errStream, $errRow);
                }

                rewind($errStream);
                $errCsvContent = stream_get_contents($errStream);
                fclose($errStream);

                $errFilename = "imports/{$this->userId}/errors_{$this->importId}.csv";
                $disk->put($errFilename, $errCsvContent);
                
                if ($diskName === 's3') {
                    $errorFileUrl = $disk->temporaryUrl($errFilename, now()->addDays(7));
                } else {
                    $errorFileUrl = $disk->url($errFilename);
                }
            }

            // 5. Cập nhật trạng thái import_jobs sử dụng Eloquent
            ImportJob::where('id', $this->importId)->update([
                'status' => $successRows === $totalRows ? 'completed' : ($successRows === 0 ? 'failed' : 'completed'),
                'success_rows' => $successRows,
                'failed_rows' => $failedRows,
                'total_rows' => $totalRows,
                'error_file_url' => $errorFileUrl,
            ]);

            Log::info("Nhập file giao dịch hoàn tất. Thành công: {$successRows}, Thất bại: {$failedRows}");

            // 6. Gửi thông báo cho user
            $user->notify(new \App\Notifications\ImportCompletedNotification(
                $this->importId, $successRows, $failedRows, $totalRows, $errorFileUrl
            ));

        } catch (\Throwable $e) {
            Log::error("Lỗi khi xử lý nhập file giao dịch: " . $e->getMessage());

            // Cập nhật trạng thái thất bại sử dụng Eloquent
            ImportJob::where('id', $this->importId)->update([
                'status' => 'failed',
            ]);

            try {
                $user = User::find($this->userId);
                if ($user) {
                    $user->notify(new \App\Notifications\ImportCompletedNotification(
                        $this->importId, 0, 0, 0, null
                    ));
                }
            } catch (\Throwable $ex) {
                Log::error("Không thể gửi thông báo lỗi: " . $ex->getMessage());
            }
        }
    }

    /**
     * Map các tiêu đề cột sang trường dữ liệu tương ứng
     */
    private function mapHeaders(array $header): array
    {
        $map = [
            'transaction_date' => null,
            'type' => null,
            'amount' => null,
            'wallet' => null,
            'category' => null,
            'title' => null,
            'notes' => null,
            'currency_code' => null
        ];

        foreach ($header as $index => $col) {
            $colClean = strtolower(trim($col));

            if (in_array($colClean, ['ngày', 'ngày giao dịch', 'date', 'transaction_date'])) {
                $map['transaction_date'] = $index;
            } elseif (in_array($colClean, ['loại', 'loại giao dịch', 'type'])) {
                $map['type'] = $index;
            } elseif (in_array($colClean, ['số tiền', 'tiền', 'amount'])) {
                $map['amount'] = $index;
            } elseif (in_array($colClean, ['ví', 'wallet', 'wallet_id'])) {
                $map['wallet'] = $index;
            } elseif (in_array($colClean, ['danh mục', 'category', 'category_id'])) {
                $map['category'] = $index;
            } elseif (in_array($colClean, ['tiêu đề', 'tên', 'title'])) {
                $map['title'] = $index;
            } elseif (in_array($colClean, ['ghi chú', 'notes', 'note'])) {
                $map['notes'] = $index;
            } elseif (in_array($colClean, ['đơn vị', 'tiền tệ', 'currency', 'currency_code'])) {
                $map['currency_code'] = $index;
            }
        }

        return $map;
    }

    private function getVal(array $row, ?int $index)
    {
        if ($index === null || !isset($row[$index])) {
            return null;
        }
        return trim($row[$index]);
    }
}
