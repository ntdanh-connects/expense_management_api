<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecurringExecution extends Model
{
    protected $table = 'recurring_executions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // Chỉ có created_at

    protected $fillable = [
        'id',
        'recurring_rule_id',
        'transaction_id',
        'executed_at',
        'status',
        'error_message',
        'created_at'
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'created_at' => 'datetime'
    ];

    protected static function booted()
    {
        static::creating(function ($execution) {
            if (empty($execution->id)) {
                $execution->id = (string) Str::uuid7();
            }
            if (empty($execution->created_at)) {
                $execution->created_at = now();
            }
        });
    }

    public function rule()
    {
        return $this->belongsTo(RecurringRule::class, 'recurring_rule_id', 'id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }
}
