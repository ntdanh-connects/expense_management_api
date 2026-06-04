<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BudgetAlert extends Model
{
    protected $table = 'budget_alerts';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false; // Bảng budget_alerts trong sql chỉ có triggered_at

    protected $fillable = [
        'id', 'budget_id', 'threshold_percent', 'triggered_at'
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($alert) {
            if (empty($alert->id)) {
                $alert->id = (string) Str::uuid7();
            }
            if (empty($alert->triggered_at)) {
                $alert->triggered_at = now();
            }
        });
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class, 'budget_id', 'id');
    }
}
