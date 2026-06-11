<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserDeviceToken extends Model
{
    protected $table = 'user_device_tokens';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'device_token',
        'device_type'
    ];

    protected static function booted()
    {
        static::creating(function ($token) {
            if (empty($token->id)) {
                $token->id = (string) Str::uuid7();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
