<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RecurringRule extends Model
{
    use SoftDeletes;

    protected $table = 'recurring_rules';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'wallet_id',
        'category_id',
        'payee_id',
        'type',
        'amount',
        'title',
        'frequency',
        'interval_value',
        'start_date',
        'next_run_at',
        'end_at',
        'is_active'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'interval_value' => 'integer',
        'start_date' => 'datetime',
        'next_run_at' => 'datetime',
        'end_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'last_executed_at',
        'is_executed_current_period',
    ];

    public function getLastExecutedAtAttribute()
    {
        $lastExec = \Illuminate\Support\Facades\DB::table('recurring_executions')
            ->where('recurring_rule_id', $this->id)
            ->whereIn('status', ['success', 'skipped'])
            ->orderBy('executed_at', 'desc')
            ->value('executed_at');

        return $lastExec ? \Illuminate\Support\Carbon::parse($lastExec)->timezone('Asia/Ho_Chi_Minh')->toIso8601String() : null;
    }

    public function getIsExecutedCurrentPeriodAttribute()
    {
        $lastExecStr = $this->last_executed_at;
        if (!$lastExecStr) {
            return false;
        }

        $lastExec = \Illuminate\Support\Carbon::parse($lastExecStr)->timezone('Asia/Ho_Chi_Minh');
        $now = \Illuminate\Support\Carbon::now('Asia/Ho_Chi_Minh');

        switch ($this->frequency) {
            case 'weekly':
                return $lastExec->between($now->copy()->startOfWeek(), $now->copy()->endOfWeek());
            case 'monthly':
                return $lastExec->between($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
            case 'yearly':
                return $lastExec->between($now->copy()->startOfYear(), $now->copy()->endOfYear());
            default:
                return false; // Bỏ hàng ngày theo yêu cầu người dùng
        }
    }

    protected static function booted()
    {
        static::creating(function ($rule) {
            if (empty($rule->id)) {
                $rule->id = (string) Str::uuid7();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    public function payee()
    {
        return $this->belongsTo(SavedPayee::class, 'payee_id', 'id');
    }

    public function executions()
    {
        return $this->hasMany(RecurringExecution::class, 'recurring_rule_id', 'id');
    }
}
