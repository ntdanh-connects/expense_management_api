<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ImportJob extends Model
{
    protected $table = 'import_jobs';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'file_url',
        'status',
        'success_rows',
        'failed_rows',
        'total_rows',
        'error_file_url'
    ];

    protected $casts = [
        'success_rows' => 'integer',
        'failed_rows' => 'integer',
        'total_rows' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
