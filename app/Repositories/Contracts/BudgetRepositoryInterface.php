<?php

namespace App\Repositories\Contracts;

interface BudgetRepositoryInterface extends BaseRepositoryInterface
{
    public function getBudgetsWithUsage(string $userId, int $month, int $year);
    public function findExistingBudget(string $userId, ?string $categoryId, int $month, int $year);
}
