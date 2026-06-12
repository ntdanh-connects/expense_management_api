<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Wallet extends Model
{
    use SoftDeletes;

    protected $table = 'wallets';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'name', 'type', 'currency_code', 'icon', 'color', 'is_hidden', 'bank_code', 'account_number'
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
    ];

    protected static function booted()
    {
        static::creating(function ($wallet) {
            if (empty($wallet->id)) {
                $wallet->id = (string) Str::uuid7();
            }
        });
    }
}