<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\RecurringRuleRepositoryInterface;
use App\Models\RecurringRule;

class RecurringRuleRepository extends BaseRepository implements RecurringRuleRepositoryInterface
{
    public function getModel()
    {
        return RecurringRule::class;
    }

    public function getActiveRulesDue()
    {
        return $this->model->newQuery()
            ->where('is_active', true)
            ->where('next_run_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('end_at')
                      ->orWhere('end_at', '>', now());
            })
            ->get();
    }
}
