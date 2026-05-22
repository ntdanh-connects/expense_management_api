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
}