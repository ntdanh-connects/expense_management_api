<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class WalletRepository extends BaseRepository implements WalletRepositoryInterface {

    public function getModel()
    {
        return Wallet::class;
    }

    // 🔥 Hàm lấy full ví kèm số dư khả dụng thực tế của user
    public function getWalletsByUserId(string $userId)
    {
        $wallets = DB::table('wallets')
            ->join('wallet_balances', 'wallets.id', '=', 'wallet_balances.wallet_id')
            ->where('wallets.user_id', $userId)
            ->whereNull('wallets.deleted_at') // Loại bỏ ví đã xóa mềm
            ->select(
                'wallets.id', 'wallets.name', 'wallets.type', 'wallets.icon', 
                'wallets.color', 'wallets.is_hidden', 'wallets.created_at',
                'wallets.currency_code', 'wallets.is_default_receiving',
                'wallets.minimum_balance', 'wallets.is_minimum_balance_alert_enabled',
                'wallet_balances.available_balance', 'wallet_balances.version'
            )
            ->orderBy('wallets.created_at', 'desc')
            ->get();

        foreach ($wallets as $wallet) {
            if ($wallet->type === 'ewallet') {
                $wallet->type = 'e-wallet';
            }
        }

        return $wallets;
    }

    // Khởi tạo dòng số dư ban đầu cho ví mới cứng
    public function initWalletBalance(string $walletId, float $initialBalance)
    {
        return DB::table('wallet_balances')->insert([
            'wallet_id'           => $walletId,
            'available_balance'   => $initialBalance,
            'version'             => 1, // Khởi tạo phiên bản bảo mật bằng 1
            'last_transaction_id' => null,
            'updated_at'          => now()
        ]);
    }
}