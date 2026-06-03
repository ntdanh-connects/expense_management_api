<?php

namespace App\Console\Commands;

use App\Services\RecurringTransactionService;
use Illuminate\Console\Command;

class ProcessRecurringTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recurring:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Quét và tự động thực thi các giao dịch định kỳ đến hạn';

    protected $recurringService;

    public function __construct(RecurringTransactionService $recurringService)
    {
        parent::__construct();
        $this->recurringService = $recurringService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Bắt đầu quét giao dịch định kỳ...');
        
        $count = $this->recurringService->processRecurringRules();
        
        $this->info("Hoàn thành! Đã thực thi thành công {$count} giao dịch định kỳ.");
        
        return Command::SUCCESS;
    }
}
