<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TransactionSplit extends Model
{
    protected $table = 'transaction_splits';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'transaction_id',
        'wallet_id',
        'amount',
        'amount_in_user_currency',
        'exchange_rate',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_in_user_currency' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
    ];

    protected static function booted()
    {
        static::creating(function ($split) {
            if (empty($split->id)) {
                $split->id = method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::uuid();
            }
        });
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }
}
