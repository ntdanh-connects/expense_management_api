<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SavingsGoal extends Model
{
    protected $table = 'savings_goals';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'name',
        'target_amount',
        'current_amount',
        'target_date',
        'status',
        'auto_save_frequency',
        'auto_save_amount',
        'source_wallet_id',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'auto_save_amount' => 'decimal:2',
        'target_date' => 'date',
    ];

    protected static function booted()
    {
        static::creating(function ($goal) {
            if (empty($goal->id)) {
                $goal->id = (string) Str::uuid7();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function sourceWallet()
    {
        return $this->belongsTo(Wallet::class, 'source_wallet_id', 'id');
    }

    public function transactions()
    {
        return $this->hasMany(SavingsTransaction::class, 'savings_goal_id', 'id');
    }
}
