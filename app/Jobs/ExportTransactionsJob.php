<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Models\User;
use App\Models\ReportExport;
use Illuminate\Support\Facades\Notification;

class ExportTransactionsJob implements ShouldQueue
{
    use Queueable;

    protected $userId;
    protected $exportId;
    protected $filters;

    /**
     * Create a new job instance.
     */
    public function __construct(string $userId, string $exportId, array $filters)
    {
        $this->userId = $userId;
        $this->exportId = $exportId;
        $this->filters = $filters;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Bắt đầu xử lý xuất giao dịch cho user: {$this->userId}, export_id: {$this->exportId}");

        // 1. Cập nhật trạng thái thành processing sử dụng Eloquent
        ReportExport::where('id', $this->exportId)->update([
            'status' => 'processing',
        ]);

        try {
            $user = User::find($this->userId);
            if (!$user) {
                throw new \Exception("Không tìm thấy người dùng.");
            }

            // 2. Query giao dịch theo bộ lọc
            $query = DB::table('transactions')
                ->leftJoin('wallets', 'transactions.wallet_id', '=', 'wallets.id')
                ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
                ->where('transactions.user_id', $this->userId)
                ->whereNull('transactions.deleted_at');

            if (!empty($this->filters['wallet_id'])) {
                $query->where('transactions.wallet_id', $this->filters['wallet_id']);
            }

            if (!empty($this->filters['category_id'])) {
                $query->where('transactions.category_id', $this->filters['category_id']);
            }

            if (!empty($this->filters['start_date'])) {
                $query->where('transactions.transaction_date', '>=', Carbon::parse($this->filters['start_date'])->startOfDay());
            }

            if (!empty($this->filters['end_date'])) {
                $query->where('transactions.transaction_date', '<=', Carbon::parse($this->filters['end_date'])->endOfDay());
            }

            $transactions = $query->select(
                'transactions.id',
                'transactions.transaction_date',
                'transactions.type',
                'transactions.amount',
                'transactions.currency_code',
                'transactions.amount_in_user_currency',
                'wallets.name as wallet_name',
                'categories.name as category_name',
                'transactions.title',
                'transactions.notes',
                'transactions.status'
            )->orderBy('transactions.transaction_date', 'desc')->get();

            // 3. Tạo file CSV
            $tempStream = fopen('php://temp', 'r+');
            
            // UTF-8 BOM để Excel đọc đúng tiếng Việt có dấu
            fprintf($tempStream, chr(0xEF).chr(0xBB).chr(0xBF));

            // Viết Headers
            fputcsv($tempStream, [
                'Mã Giao Dịch', 
                'Ngày Giao Dịch', 
                'Loại Giao Dịch', 
                'Số Tiền', 
                'Đơn Vị', 
                'Số Tiền Quy Đổi', 
                'Ví', 
                'Danh Mục', 
                'Tiêu Đề', 
                'Ghi Chú', 
                'Trạng Thái'
            ]);

            // Viết Dữ liệu
            foreach ($transactions as $tx) {
                fputcsv($tempStream, [
                    $tx->id,
                    Carbon::parse($tx->transaction_date)->toDateTimeString(),
                    $tx->type === 'income' ? 'Thu nhập' : 'Chi tiêu',
                    (float) $tx->amount,
                    $tx->currency_code,
                    (float) $tx->amount_in_user_currency,
                    $tx->wallet_name ?? 'Ví đã xóa',
                    $tx->category_name ?? 'Không phân mục',
                    $tx->title,
                    $tx->notes,
                    $tx->status
                ]);
            }

            rewind($tempStream);
            $csvContent = stream_get_contents($tempStream);
            fclose($tempStream);

            // 4. Lưu file lên S3 / Local Disk
            $filename = "exports/{$this->userId}/transactions_{$this->exportId}.csv";
            $diskName = config('filesystems.default') === 's3' ? 's3' : 'public';
            $disk = Storage::disk($diskName);
            
            $disk->put($filename, $csvContent);
            
            if ($diskName === 's3') {
                $fileUrl = $disk->temporaryUrl($filename, now()->addDays(7));
            } else {
                $fileUrl = $disk->url($filename);
            }

            // 5. Cập nhật trạng thái completed sử dụng Eloquent
            ReportExport::where('id', $this->exportId)->update([
                'status' => 'completed',
                'file_url' => $fileUrl,
                'exported_at' => now(),
            ]);

            Log::info("Xuất file giao dịch thành công cho user: {$this->userId}, URL: {$fileUrl}");

            // 6. Gửi thông báo cho user
            $user->notify(new \App\Notifications\ExportCompletedNotification($this->exportId, true, $fileUrl));

        } catch (\Throwable $e) {
            Log::error("Lỗi khi xuất giao dịch: " . $e->getMessage());

            // Cập nhật trạng thái thất bại sử dụng Eloquent
            ReportExport::where('id', $this->exportId)->update([
                'status' => 'failed',
            ]);

            // Gửi thông báo lỗi
            try {
                $user = User::find($this->userId);
                if ($user) {
                    $user->notify(new \App\Notifications\ExportCompletedNotification($this->exportId, false, null, $e->getMessage()));
                }
            } catch (\Throwable $ex) {
                Log::error("Không thể gửi thông báo lỗi xuất file: " . $ex->getMessage());
            }
        }
    }
}
