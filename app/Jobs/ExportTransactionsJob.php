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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

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

            // 3. Tạo file Excel (.xlsx) sử dụng PhpSpreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Giao dịch');

            // Viết Headers
            $headers = [
                'Mã Giao Dịch', 
                'Ngày Giao Dịch', 
                'Loại Giao Dịch', 
                'Số Tiền', 
                'Đơn Vị', 
                'Ví', 
                'Danh Mục', 
                'Tiêu Đề', 
                'Ghi Chú', 
                'Trạng Thái'
            ];

            $colIndex = 1;
            foreach ($headers as $header) {
                $sheet->setCellValue([$colIndex, 1], $header);
                $colIndex++;
            }

            // Viết Dữ liệu
            $rowIndex = 2;
            foreach ($transactions as $tx) {
                $sheet->setCellValue([1, $rowIndex], $tx->id);
                $sheet->setCellValue([2, $rowIndex], Carbon::parse($tx->transaction_date)->toDateTimeString());
                $sheet->setCellValue([3, $rowIndex], $tx->type === 'income' ? 'Thu nhập' : 'Chi tiêu');
                $sheet->setCellValue([4, $rowIndex], (float) $tx->amount);
                $sheet->setCellValue([5, $rowIndex], $tx->currency_code);
                $sheet->setCellValue([6, $rowIndex], $tx->wallet_name ?? 'Ví đã xóa');
                $sheet->setCellValue([7, $rowIndex], $tx->category_name ?? 'Không phân mục');
                
                // Tiêu đề & Ghi chú ép kiểu string tường minh
                $sheet->setCellValueExplicit([8, $rowIndex], $tx->title ?? '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([9, $rowIndex], $tx->notes ?? '', DataType::TYPE_STRING);
                
                $sheet->setCellValue([10, $rowIndex], $tx->status);
                $rowIndex++;
            }

            $totalRows = $rowIndex - 1;

            // --- Cấu hình Styling đẹp cho file Excel ---
            // 1. Phần tiêu đề của tất cả các cột căn giữa, in đậm và chữ to lên (font size 12)
            $headerRange = 'A1:J1';
            $sheet->getStyle($headerRange)->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            if ($totalRows >= 2) {
                // 2. Cột ngày giao dịch, loại giao dịch, số tiền, đơn vị, ví, trạng thái căn giữa
                // Tương ứng cột B, C, D, E, F, J
                $centerCols = ['B', 'C', 'D', 'E', 'F', 'J'];
                foreach ($centerCols as $col) {
                    $sheet->getStyle("{$col}2:{$col}{$totalRows}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                // 3. Phần tiền hiện format tiền việt (Cột D)
                $sheet->getStyle("D2:D{$totalRows}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0" ₫"');

                // 4. Tô viền các ô dữ liệu (Thin border)
                $borderStyle = [
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['argb' => 'D0D0D0'],
                        ],
                    ],
                ];
                $sheet->getStyle("A1:J{$totalRows}")->applyFromArray($borderStyle);
            }

            // Tự động điều chỉnh độ rộng cột
            foreach (range('A', 'J') as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }

            // Render Excel content
            $writer = new Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $excelContent = ob_get_clean();

            // 4. Lưu file lên S3 / Local Disk
            $filename = "exports/{$this->userId}/transactions_{$this->exportId}.xlsx";
            $diskName = config('filesystems.default') === 's3' ? 's3' : 'public';
            $disk = Storage::disk($diskName);
            
            $disk->put($filename, $excelContent);
            
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
