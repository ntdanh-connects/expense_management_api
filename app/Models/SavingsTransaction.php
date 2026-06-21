<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SavingsTransaction extends Model
{
    protected $table = 'savings_transactions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'savings_goal_id',
        'type',
        'amount',
        'source_wallet_id',
        'transaction_date',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($transaction) {
            if (empty($transaction->id)) {
                $transaction->id = (string) Str::uuid7();
            }
        });
    }

    public function savingsGoal()
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id', 'id');
    }

    public function sourceWallet()
    {
        return $this->belongsTo(Wallet::class, 'source_wallet_id', 'id');
    }
}
