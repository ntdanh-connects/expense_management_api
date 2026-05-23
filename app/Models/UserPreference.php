<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model{
    protected $table = 'user_preferences';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'language', 'theme', 'currency', 'timezone', 'financial_start_day', 'created_at'
    ];
}