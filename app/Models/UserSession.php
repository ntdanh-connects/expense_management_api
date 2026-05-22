<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $table = 'user_sessions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // Bảng này chỉ có created_at

    protected $fillable = [
        'id', 'user_id', 'refresh_token_hash', 'device_type', 
        'device_name', 'ip_address', 'user_agent', 'expired_at', 'created_at'
    ];
}