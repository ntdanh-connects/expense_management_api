<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'action',
        'description',
        'ip_address',
        'user_agent'
    ];

    protected static function booted()
    {
        static::creating(function ($log) {
            if (empty($log->id)) {
                $log->id = (string) Str::uuid7();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
