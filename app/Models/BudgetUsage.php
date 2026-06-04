<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetUsage extends Model
{
    protected $table = 'budget_usages';
    protected $primaryKey = 'budget_id';
    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null; // Bảng budget_usages trong sql không có created_at

    protected $fillable = [
        'budget_id', 'used_amount'
    ];

    public function budget()
    {
        return $this->belongsTo(Budget::class, 'budget_id', 'id');
    }
}
