<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use SoftDeletes;

    protected $table = 'transactions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'wallet_id',
        'category_id',
        'type',
        'status',
        'amount',
        'currency_code',
        'exchange_rate',
        'title',
        'notes',
        'transaction_date',
        'source_type',
        'source_id'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
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

    public function attachments()
    {
        return $this->hasMany(TransactionAttachment::class, 'transaction_id', 'id');
    }

    public function audits()
    {
        return $this->hasMany(TransactionAudit::class, 'transaction_id', 'id');
    }
}
