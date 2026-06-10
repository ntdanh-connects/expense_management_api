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

    protected $fillable = ['user_id', 'email', 'status', 'email_verified_at', 'google_id', 'github_id'];

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
}
