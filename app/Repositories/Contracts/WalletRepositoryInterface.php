<?php
namespace App\Repositories\Contracts;

interface WalletRepositoryInterface extends BaseRepositoryInterface{
    public function getWalletsByUserId(string $userId);
    public function initWalletBalance(string $walletId, float $initialBalance);
}