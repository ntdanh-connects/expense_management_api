<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TransactionAudit extends Model
{
    protected $table = 'transaction_audits';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // Chỉ có created_at

    protected $fillable = [
        'id',
        'transaction_id',
        'old_data',
        'new_data',
        'changed_by',
        'created_at'
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'created_at' => 'datetime'
    ];

    protected static function booted()
    {
        static::creating(function ($audit) {
            if (empty($audit->id)) {
                $audit->id = (string) Str::uuid7();
            }
            if (empty($audit->created_at)) {
                $audit->created_at = now();
            }
        });
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}
