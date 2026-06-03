<?php

namespace App\Repositories\Contracts;

interface TransactionRepositoryInterface extends BaseRepositoryInterface
{
    public function getFilteredTransactions(string $userId, array $filters, string $sortBy, string $sortOrder, int $perPage);
}
