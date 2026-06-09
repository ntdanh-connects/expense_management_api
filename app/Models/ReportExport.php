<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ReportExport extends Model
{
    protected $table = 'report_exports';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    // Bảng này không có cột updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'user_id',
        'type',
        'status',
        'file_url',
        'filters',
        'exported_at',
        'created_at'
    ];

    protected $casts = [
        'filters' => 'array',
        'exported_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
