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
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $wallets = $this->walletService->getAllUserWallets($userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.sync_wallets_success'),
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
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            // Validate khít 100% tên trường trong file SQL thực tế của Danh

            $validated = $request->validate([
                'name'              => 'required|string',
                'type'              => 'required|string', // cash, bank, ewallet, crypto
                'icon'              => 'nullable|string',
                'color'             => 'nullable|string',
                'is_hidden'         => 'nullable|boolean',
                'currency_code'     => 'nullable|string|in:VND',
            ]);

            $wallet = $this->walletService->createNewWallet($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.create_wallet_success'),
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
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'name'          => 'sometimes|required|string',
                'type'          => 'sometimes|required|string',
                'icon'          => 'nullable|string',
                'color'         => 'nullable|string',
                'is_hidden'     => 'nullable|boolean',
                'currency_code' => 'sometimes|required|string|in:VND'
            ]);

            $wallet = $this->walletService->updateWallet($id, $userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.update_wallet_success'),
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
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $this->walletService->deleteWallet($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.delete_wallet_success')
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
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $validated = $request->validate([
                'from_wallet_id' => 'required|uuid',
                'to_wallet_id'   => 'required|uuid',
                'amount'         => 'required|numeric|min:0.01',
                'notes'          => 'nullable|string|max:500',
                'timezone'       => 'nullable|string|timezone'
            ]);

            $result = $this->walletService->transferMoney($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.transfer_success'),
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
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            $perPage = (int) $request->query('per_page', 20);

            $transactions = $this->walletService->getWalletTransactions($id, $userId, $perPage);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.get_transactions_success'),
                'data'    => $transactions
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    //API 7: GET /api/wallets/transfers (Lịch sử chuyển tiền nội bộ của tất cả các ví)
    public function getTransfers(Request $request)
    {
        try {
            $userId = $request->attributes->get('user_id');

            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            // Lấy tất cả lịch sử chuyển khoản nội bộ liên quan tới các ví thuộc sở hữu của user
            $transfers = \Illuminate\Support\Facades\DB::table('wallet_transfers')
                ->join('wallets as from_wallet', 'wallet_transfers.from_wallet_id', '=', 'from_wallet.id')
                ->join('wallets as to_wallet', 'wallet_transfers.to_wallet_id', '=', 'to_wallet.id')
                ->where('from_wallet.user_id', $userId)
                ->where('to_wallet.user_id', $userId)
                ->select([
                    'wallet_transfers.id',
                    'from_wallet.name as from_wallet_name',
                    'to_wallet.name as to_wallet_name',
                    'wallet_transfers.amount',
                    'wallet_transfers.timezone',
                    'from_wallet.currency_code as currency_code',
                    'wallet_transfers.created_at as date'
                ])
                ->orderBy('wallet_transfers.created_at', 'desc')
                ->get();

            // Format date chuẩn ISO 8601 để frontend parse dễ dàng
            $transfers = $transfers->map(function ($item) {
                return [
                    'id'               => $item->id,
                    'from_wallet_name' => $item->from_wallet_name,
                    'to_wallet_name'   => $item->to_wallet_name,
                    'amount'           => (float) $item->amount,
                    'timezone'         => $item->timezone,
                    'currency_code'    => $item->currency_code,
                    'date'             => \Illuminate\Support\Carbon::parse($item->date)->toIso8601String()
                ];
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Lấy lịch sử chuyển khoản nội bộ thành công!',
                'data'    => $transfers
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    //API 8: POST /api/wallets/{id}/set-default-receiving (Đặt ví nhận tiền mặc định)
    public function setDefaultReceiving(Request $request, $id)
    {
        try {
            $userId = $request->attributes->get('user_id');

            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
            }

            // Kiểm tra ví tồn tại và thuộc quyền sở hữu của user
            $wallet = \Illuminate\Support\Facades\DB::table('wallets')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->first();

            if (!$wallet) {
                return response()->json(['status' => 'error', 'message' => __('messages.wallet_not_found_or_unauthorized')], 404);
            }

            // Chỉ cho phép đặt mặc định cho ví Ngân hàng hoặc Ví điện tử
            if (!in_array($wallet->type, ['bank', 'ewallet'])) {
                return response()->json(['status' => 'error', 'message' => 'Chỉ được phép đặt ví mặc định nhận tiền cho ví Ngân hàng hoặc Ví điện tử.'], 400);
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($userId, $id) {
                // Đặt tất cả các ví khác của user này về false
                \Illuminate\Support\Facades\DB::table('wallets')
                    ->where('user_id', $userId)
                    ->update(['is_default_receiving' => false]);

                // Đặt ví này thành true
                \Illuminate\Support\Facades\DB::table('wallets')
                    ->where('id', $id)
                    ->update(['is_default_receiving' => true]);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Đặt ví nhận tiền mặc định thành công!'
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}