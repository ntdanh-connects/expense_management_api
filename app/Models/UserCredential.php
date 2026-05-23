<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCredential extends Model{
    protected $table = 'user_credentials';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

   protected $fillable = ['user_id', 'password_hash', 'failed_login_attempts', 'locked_until', 'password_changed_at'];
}