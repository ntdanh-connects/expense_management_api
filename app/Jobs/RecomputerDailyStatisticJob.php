<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RecomputerDailyStatisticJob implements ShouldQueue, ShouldBeUnique{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uniqueFor = 30;

    public function uniqueId(): string{
        return "{$this->userId}:{$this->date}";
    }

    public function __construct(protected string $userId,
        protected string $date)
    {
        
    }

    public function handle(): void{
         
        //Tính toán tổng Income/Expense của ngày đó từ bảng transactions
        $stats = DB::table('transactions')->select(
            DB::raw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount_in_user_currency ELSE 0 END),0) as total_income"),
            DB::raw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount_in_user_currency ELSE 0 END),0) as total_expense")
        )
        ->where('user_id', $this->userId)
        ->whereNull('deleted_at')
        ->where('status', 'completed')
        ->where(DB::raw("(transaction_date AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Ho_Chi_Minh')::date"), $this->date)
        ->first()
        ;

        DB::table('daily_statistics')->upsert([
            'user_id' => $this->userId,
            'date' => $this->date,
            'income' => $stats->total_income,
            'expense' => $stats->total_expense,
            'updated_at' => now()
        ], ['user_id', 'date'], ['income', 'expense', 'updated_at']);
    }
}