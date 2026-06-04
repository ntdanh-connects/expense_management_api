<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class Budget extends Model
{
    protected $table = 'budgets';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'category_id', 'limit_amount', 'month', 'year'
    ];

    protected static function booted()
    {
        static::creating(function ($budget) {
            if (empty($budget->id)) {
                $budget->id = (string) Str::uuid7();
            }
        });

        static::deleting(function ($budget) {
            // Xóa cascade trong code để tránh lỗi vi phạm khóa ngoại
            DB::table('budget_usages')->where('budget_id', $budget->id)->delete();
            DB::table('budget_alerts')->where('budget_id', $budget->id)->delete();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function usage()
    {
        return $this->hasOne(BudgetUsage::class, 'budget_id', 'id');
    }

    public function alerts()
    {
        return $this->hasMany(BudgetAlert::class, 'budget_id', 'id');
    }
}
