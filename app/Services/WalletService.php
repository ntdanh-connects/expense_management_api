<?php

namespace App\Services;

use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Support\Facades\DB;

class WalletService {
    protected $walletRepository;

    public function __construct(WalletRepositoryInterface $walletRepository)
    {
        $this->walletRepository = $walletRepository;
    }

    public function getAllUserWallets(string $userId)
    {
        return $this->walletRepository->getWalletsByUserId($userId);
    }

    // 🔥 Kích hoạt tạo ví đồng bộ 2 bảng sạch sẽ theo SQL
    public function createNewWallet(string $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            
            // 1. Tạo ví mới cứng
            $wallet = $this->walletRepository->create([
                'user_id'   => $userId,
                'name'      => $data['name'],
                'type'      => $data['type'],
                'icon'      => $data['icon'] ?? null,
                'color'     => $data['color'] ?? null,
                'is_hidden' => $data['is_hidden'] ?? false,
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
        
        if (!$wallet || $wallet->user_id !== $userId) {
            throw new \Exception("Ví không tồn tại hoặc bạn không có quyền chỉnh sửa!");
        }

        $wallet->update($data);
        return $wallet;
    }

    public function deleteWallet(string $walletId, string $userId)
    {
        $wallet = $this->walletRepository->find($walletId);
        
        if (!$wallet || $wallet->user_id !== $userId) {
            throw new \Exception("Ví không tồn tại hoặc không có quyền thao tác!");
        }

        // Check số dư hiện tại từ bảng balance trước khi cho xóa mềm
        $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_balance');
        if ((float)$balance > 0) {
            throw new \Exception("Ví hiện tại vẫn còn tiền dư, không thể xóa!");
        }

        return $wallet->delete();
    }
}