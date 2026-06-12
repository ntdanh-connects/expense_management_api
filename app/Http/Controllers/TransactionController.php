<?php

namespace App\Http\Controllers;

use App\Services\TransactionService;
use App\Jobs\ExportTransactionsJob;
use App\Jobs\ImportTransactionsJob;
use App\Models\ReportExport;
use App\Models\ImportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * API 1: Lấy danh sách giao dịch có bộ lọc và phân trang
     * GET /api/transactions
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            // Thu thập các filter
            $filters = $request->only([
                'search',
                'start_date',
                'end_date',
                'category_id',
                'type',
                'min_amount',
                'max_amount',
                'wallet_id'
            ]);

            $sortBy = $request->query('sort_by', 'date');
            $sortOrder = $request->query('sort_order', 'desc');
            $perPage = (int) $request->query('per_page', 20);

            $transactions = $this->transactionService->getTransactions($userId, $filters, $sortBy, $sortOrder, $perPage);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.get_transactions_list_success'),
                'data'    => $transactions
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * API 2: Tạo giao dịch thủ công (POST /api/transactions)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'wallet_id'        => 'required|uuid',
                'category_id'      => 'nullable|uuid',
                'type'             => 'required|string|in:income,expense',
                'amount'           => 'required|numeric|min:0.01',
                'title'            => 'required|string|max:255',
                'notes'            => 'nullable|string|max:1000',
                'transaction_date' => 'nullable|date',
                'currency_code'    => 'nullable|string|max:10',
                'exchange_rate'    => 'nullable|numeric|min:0.000001',
                'timezone'         => 'nullable|string|timezone',
                'attachment'       => 'nullable|file|image|mimes:jpeg,png,jpg,gif|max:102400', // Tối đa 100MB
                'attachments'      => 'nullable|array',
                'attachments.*'    => 'nullable|file|image|mimes:jpeg,png,jpg,gif|max:102400'
            ]);

            $wallet = DB::table('wallets')->where('id', $validated['wallet_id'])->where('user_id', $userId)->first();
            if (!$wallet) {
                return response()->json(['status' => 'error', 'message' => 'Ví không tồn tại hoặc bạn không có quyền sử dụng ví này.'], 404);
            }

            if ($wallet->type !== 'cash') {
                return response()->json(['status' => 'error', 'message' => 'Giao dịch thủ công chỉ cho phép sử dụng ví Tiền mặt.'], 400);
            }

            if ($wallet->currency_code !== 'VND') {
                return response()->json(['status' => 'error', 'message' => 'Giao dịch thủ công chỉ hỗ trợ đơn vị tiền tệ VND.'], 400);
            }
 
            $transaction = $this->transactionService->createTransaction($userId, $validated, $request->file('attachment'), $request->file('attachments'));

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.create_transaction_success'),
                'data'    => $transaction
            ], 201);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API 3: Chi tiết giao dịch (GET /api/transactions/{id})
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $transaction = $this->transactionService->getTransactionById($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.get_transaction_detail_success'),
                'data'    => $transaction
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API 4: Chỉnh sửa giao dịch (POST /api/transactions/{id} để hỗ trợ gửi ảnh qua multipart/form-data)
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'wallet_id'        => 'sometimes|required|uuid',
                'category_id'      => 'nullable|uuid',
                'type'             => 'sometimes|required|string|in:income,expense',
                'amount'           => 'sometimes|required|numeric|min:0.01',
                'title'            => 'sometimes|required|string|max:255',
                'notes'            => 'nullable|string|max:1000',
                'transaction_date' => 'sometimes|required|date',
                'currency_code'    => 'nullable|string|max:10',
                'exchange_rate'    => 'nullable|numeric|min:0.000001',
                'timezone'         => 'nullable|string|timezone',
                'attachment'       => 'nullable|file|image|mimes:jpeg,png,jpg,gif|max:102400',
                'attachments'      => 'nullable|array',
                'attachments.*'    => 'nullable|file|image|mimes:jpeg,png,jpg,gif|max:102400'
            ]);

            if (isset($validated['wallet_id'])) {
                $wallet = DB::table('wallets')->where('id', $validated['wallet_id'])->where('user_id', $userId)->first();
                if (!$wallet) {
                    return response()->json(['status' => 'error', 'message' => 'Ví không tồn tại hoặc bạn không có quyền sử dụng ví này.'], 404);
                }
                if ($wallet->type !== 'cash') {
                    return response()->json(['status' => 'error', 'message' => 'Giao dịch thủ công chỉ cho phép sử dụng ví Tiền mặt.'], 400);
                }
                if ($wallet->currency_code !== 'VND') {
                    return response()->json(['status' => 'error', 'message' => 'Giao dịch thủ công chỉ hỗ trợ đơn vị tiền tệ VND.'], 400);
                }
            }
 
            $transaction = $this->transactionService->updateTransaction($id, $userId, $validated, $request->file('attachment'), $request->file('attachments'));

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.update_transaction_success'),
                'data'    => $transaction
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API 5: Xóa giao dịch (DELETE /api/transactions/{id})
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $this->transactionService->deleteTransaction($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.delete_transaction_success')
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    // =====================================================================
    // Module 7: Export / Import giao dịch qua hàng đợi (Queue)
    // =====================================================================

    /**
     * POST /api/transactions/export
     * Tạo yêu cầu xuất CSV, đẩy job vào hàng đợi
    /**
     * POST /api/transactions/export
     * Tạo bản ghi yêu cầu xuất dữ liệu ra CSV và đưa vào hàng đợi
     */
    public function requestExport(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $filters = $request->only(['wallet_id', 'category_id', 'start_date', 'end_date', 'type']);

            // Tạo bản ghi report_exports với trạng thái pending sử dụng Eloquent
            $export = ReportExport::create([
                'user_id'    => $userId,
                'status'     => 'pending',
                'filters'    => $filters,
                'created_at' => now(),
            ]);

            // Đẩy job vào hàng đợi
            ExportTransactionsJob::dispatch($userId, $export->id, $filters);

            return response()->json([
                'status'    => 'success',
                'message'   => 'Yêu cầu xuất dữ liệu đã được ghi nhận và đang được xử lý.',
                'export_id' => $export->id,
            ], 202);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/transactions/exports
     * Lấy danh sách lịch sử xuất file của người dùng
     */
    public function listExports(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $exports = ReportExport::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return response()->json([
                'status' => 'success',
                'data'   => $exports->items(),
                'pagination' => [
                    'total'        => $exports->total(),
                    'per_page'     => $exports->perPage(),
                    'current_page' => $exports->currentPage(),
                    'last_page'    => $exports->lastPage(),
                ],
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/transactions/import
     * Nhận file CSV, lưu vào storage rồi đẩy job vào hàng đợi xử lý
     */
    public function requestImport(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $request->validate([
                'file' => 'required|file|mimes:csv,txt|max:10240', // tối đa 10MB
            ]);

            // Lưu file tạm vào storage
            $diskName  = config('filesystems.default') === 's3' ? 's3' : 'public';
            $disk = Storage::disk($diskName);
            $filePath  = $request->file('file')->store("imports/{$userId}", $diskName);
            
            if ($diskName === 's3') {
                $fileUrl = $disk->temporaryUrl($filePath, now()->addDays(7));
            } else {
                $fileUrl = $disk->url($filePath);
            }

            // Tạo bản ghi import_jobs với trạng thái pending sử dụng Eloquent
            $import = ImportJob::create([
                'user_id'     => $userId,
                'file_url'    => $fileUrl,
                'status'      => 'pending',
                'total_rows'  => 0,
                'success_rows'=> 0,
                'failed_rows' => 0,
            ]);

            // Đẩy job vào hàng đợi
            ImportTransactionsJob::dispatch($userId, $import->id, $filePath);

            return response()->json([
                'status'    => 'success',
                'message'   => 'File đã được tải lên và đang được xử lý trong hàng đợi.',
                'import_id' => $import->id,
            ], 202);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/transactions/imports
     * Lấy danh sách lịch sử nhập file của người dùng
     */
    public function listImports(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $imports = ImportJob::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return response()->json([
                'status' => 'success',
                'data'   => $imports->items(),
                'pagination' => [
                    'total'        => $imports->total(),
                    'per_page'     => $imports->perPage(),
                    'current_page' => $imports->currentPage(),
                    'last_page'    => $imports->lastPage(),
                ],
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
