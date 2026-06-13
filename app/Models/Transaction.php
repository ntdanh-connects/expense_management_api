<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

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
        'payee_id',
        'type',
        'status',
        'amount',
        'currency_code',
        'exchange_rate',
        'amount_in_user_currency',
        'title',
        'notes',
        'timezone',
        'transaction_date',
        'source_type',
        'source_id'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_in_user_currency' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    protected $appends = ['is_transfer_locked'];

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

    public function payee()
    {
        return $this->belongsTo(SavedPayee::class, 'payee_id', 'id');
    }

    public function attachments()
    {
        return $this->hasMany(TransactionAttachment::class, 'transaction_id', 'id');
    }

    public function audits()
    {
        return $this->hasMany(TransactionAudit::class, 'transaction_id', 'id');
    }

    public function getIsTransferLockedAttribute()
    {
        if ($this->source_type !== 'transfer') {
            return false;
        }
        if (!$this->source_id) {
            return false;
        }
        $transfer = DB::table('wallet_transfers')->where('id', $this->source_id)->first();
        if (!$transfer) {
            return false;
        }
        $fromWallet = DB::table('wallets')->where('id', $transfer->from_wallet_id)->first();
        $toWallet = DB::table('wallets')->where('id', $transfer->to_wallet_id)->first();
        if ($fromWallet && $toWallet) {
            return $fromWallet->user_id === $toWallet->user_id;
        }
        return false;
    }
}

