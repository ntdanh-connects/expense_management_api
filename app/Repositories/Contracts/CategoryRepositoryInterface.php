<?php

namespace App\Repositories\Contracts;

interface CategoryRepositoryInterface extends BaseRepositoryInterface
{
    public function getVisibleCategories(string $userId);
}
