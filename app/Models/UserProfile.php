<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model{
    protected $table = 'user_profiles';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['user_id', 'full_name', 'avatar_url', 'created_at'];

    public function getAvatarUrlAttribute($value)
    {
        if ($value && (str_contains($value, '.amazonaws.com') || str_contains($value, 's3.'))) {
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