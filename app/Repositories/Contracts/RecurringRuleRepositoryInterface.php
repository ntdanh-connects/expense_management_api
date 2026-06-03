<?php

namespace App\Repositories\Contracts;

interface RecurringRuleRepositoryInterface extends BaseRepositoryInterface
{
    public function getActiveRulesDue();
}
