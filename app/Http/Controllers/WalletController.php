<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller {
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    //API 1: GET /api/wallets (Lấy list ví và số dư khả dụng)
    public function index(Request $request)
    {
        try {
           $userId = $request->attributes->get('user_id');
            
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => 'Vui lòng truyền user_id vào request!'], 400);
            }

            $wallets = $this->walletService->getAllUserWallets($userId);

            return response()->json([
                'status'  => 'success',
                'message' => 'Đồng bộ danh sách ví thành công!',
                'data'    => $wallets
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    //API 2: POST /api/wallets (Tạo ví mới)
    public function store(Request $request)
    {
        try {
            $userId = $request->attributes->get('user_id');

            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => 'Vui lòng truyền user_id vào request!'], 400);
            }

            // Validate khít 100% tên trường trong file SQL thực tế của Danh

            $validated = $request->validate([
                'name'              => 'required|string',
                'type'              => 'required|string', // cash, bank, ewallet, crypto
                'icon'              => 'nullable|string',
                'color'             => 'nullable|string',
                'is_hidden'         => 'nullable|boolean',
                'available_balance' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'] // Hứng số dư nạp vào bảng phụ
            ]);

            $wallet = $this->walletService->createNewWallet($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Tạo cấu trúc ví mới thành công rực rỡ!',
                'data'    => $wallet
            ], 201);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    //API 3: POST /api/wallets/{id} (Sửa cấu hình ví)
    public function update(Request $request, $id)
    {
        try {
            $userId = $request->attributes->get('user_id');

            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => 'Vui lòng truyền user_id vào request!'], 400);
            }

            $validated = $request->validate([
                'name'      => 'sometimes|required|string',
                'type'      => 'sometimes|required|string',
                'icon'      => 'nullable|string',
                'color'     => 'nullable|string',
                'is_hidden' => 'nullable|boolean'
            ]);

            $wallet = $this->walletService->updateWallet($id, $userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Cập nhật ví thành công!',
                'data'    => $wallet
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    //API 4: DELETE /api/wallets/{id} (Xóa mềm ví)
    public function destroy(Request $request, $id)
    {
        try {
            $userId = $request->attributes->get('user_id');

            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => 'Vui lòng truyền user_id vào request!'], 400);
            }

            $this->walletService->deleteWallet($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => 'Đã đưa ví vào trạng thái xóa mềm thành công!'
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    //API 5: POST /api/wallets/transfer (Chuyển tiền giữa các ví)
    public function transfer(Request $request)
    {
        try {
            $userId = $request->attributes->get('user_id');

            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => 'Vui lòng truyền user_id vào request!'], 400);
            }

            $validated = $request->validate([
                'from_wallet_id' => 'required|uuid',
                'to_wallet_id'   => 'required|uuid',
                'amount'         => 'required|numeric|min:0.01',
                'notes'          => 'nullable|string|max:500'
            ]);

            $result = $this->walletService->transferMoney($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Chuyển tiền giữa các ví thành công!',
                'data'    => $result
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    //API 6: GET /api/wallets/{id}/transactions (Lịch sử giao dịch của ví)
    public function transactions(Request $request, $id)
    {
        try {
            $userId = $request->attributes->get('user_id');

            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => 'Vui lòng truyền user_id vào request!'], 400);
            }

            $perPage = (int) $request->query('per_page', 20);

            $transactions = $this->walletService->getWalletTransactions($id, $userId, $perPage);

            return response()->json([
                'status'  => 'success',
                'message' => 'Lấy lịch sử giao dịch của ví thành công!',
                'data'    => $transactions
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}