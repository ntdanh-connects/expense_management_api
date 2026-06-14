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
        'id', 'user_id', 'name', 'type', 'currency_code', 'icon', 'color', 'is_hidden', 'bank_code', 'account_number', 'is_default_receiving'
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
        'is_default_receiving' => 'boolean',
    ];

    /**
     * Accessor for 'type' attribute to convert 'ewallet' from database to 'e-wallet' for frontend.
     */
    public function getTypeAttribute($value)
    {
        return $value === 'ewallet' ? 'e-wallet' : $value;
    }

    /**
     * Mutator for 'type' attribute to convert 'e-wallet' from frontend to 'ewallet' for database.
     */
    public function setTypeAttribute($value)
    {
        $this->attributes['type'] = $value === 'e-wallet' ? 'ewallet' : $value;
    }

    protected static function booted()
    {
        static::creating(function ($wallet) {
            if (empty($wallet->id)) {
                $wallet->id = (string) Str::uuid7();
            }
        });
    }
}