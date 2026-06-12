<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable {
    use HasApiTokens,Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['user_id', 'email', 'status', 'email_verified_at', 'google_id', 'github_id', 'identifier'];

    protected static function booted()
    {
        static::creating(function ($user) {
            if (empty($user->identifier)) {
                do {
                    $identifier = 'USR' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                } while (static::where('identifier', $identifier)->exists());
                $user->identifier = $identifier;
            }
        });
    }

    public function credential()
    {
        return $this->hasOne(UserCredential::class, 'user_id', 'user_id');
    }

    public function profile(){
        return $this->hasOne(UserProfile::class, 'user_id', 'user_id');
    }

    public function preference(){
        return $this->hasOne(UserPreference::class, 'user_id', 'user_id');
    }

    public function notificationPreference(){
        return $this->hasOne(NotificationPreference::class, 'user_id', 'user_id');
    }

    public function sessions() {
    return $this->hasMany(UserSession::class, 'user_id', 'user_id');
}

    public function savedPayees()
    {
        return $this->hasMany(SavedPayee::class, 'user_id', 'user_id');
    }
}
