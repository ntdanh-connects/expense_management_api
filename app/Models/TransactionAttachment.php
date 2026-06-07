<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TransactionAttachment extends Model
{
    protected $table = 'transaction_attachments';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // Bảng này chỉ có uploaded_at làm mốc thời gian

    protected $fillable = [
        'id',
        'transaction_id',
        'storage_provider_enum',
        'file_key',
        'file_url',
        'mime_type',
        'file_size',
        'uploaded_at'
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'file_size' => 'integer'
    ];

    protected static function booted()
    {
        static::creating(function ($attachment) {
            if (empty($attachment->id)) {
                $attachment->id = (string) Str::uuid7();
            }
            if (empty($attachment->uploaded_at)) {
                $attachment->uploaded_at = now();
            }
        });
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }

    public function getFileUrlAttribute($value)
    {
        if ($this->storage_provider_enum === 's3' && $value) {
            $parsedUrl = parse_url($value);
            if (isset($parsedUrl['path'])) {
                $path = ltrim($parsedUrl['path'], '/');
                $bucketName = config('filesystems.disks.s3.bucket');
                if ($bucketName && str_starts_with($path, $bucketName . '/')) {
                    $path = substr($path, strlen($bucketName . '/'));
                }
                try {
                    return \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($path, now()->addHours(24));
                } catch (\Exception $e) {
                    return $value;
                }
            }
        }
        return $value;
    }
}

