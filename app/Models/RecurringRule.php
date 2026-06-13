<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RecurringRule extends Model
{
    use SoftDeletes;

    protected $table = 'recurring_rules';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'wallet_id',
        'category_id',
        'payee_id',
        'type',
        'amount',
        'title',
        'frequency',
        'interval_value',
        'start_date',
        'next_run_at',
        'end_at',
        'is_active'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'interval_value' => 'integer',
        'start_date' => 'datetime',
        'next_run_at' => 'datetime',
        'end_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::creating(function ($rule) {
            if (empty($rule->id)) {
                $rule->id = (string) Str::uuid7();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    public function payee()
    {
        return $this->belongsTo(SavedPayee::class, 'payee_id', 'id');
    }

    public function executions()
    {
        return $this->hasMany(RecurringExecution::class, 'recurring_rule_id', 'id');
    }
}
