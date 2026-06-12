<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
 
class SavedPayee extends Model
{
    protected $table = 'saved_payees';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
 
    protected $fillable = [
        'id',
        'user_id',
        'payee_type',
        'payee_user_id',
        'identifier',
        'bank_code',
        'bank_name',
        'payee_name',
        'nickname',
        'last_scanned_at',
        'scan_count'
    ];
 
    protected $casts = [
        'last_scanned_at' => 'datetime',
        'scan_count' => 'integer'
    ];
 
    protected static function booted()
    {
        static::creating(function ($payee) {
            if (empty($payee->id)) {
                $payee->id = (string) Str::uuid7();
            }
        });
    }
 
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
 
    public function payeeUser()
    {
        return $this->belongsTo(User::class, 'payee_user_id', 'user_id');
    }
}
