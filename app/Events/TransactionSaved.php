<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionSaved
{
    use Dispatchable, SerializesModels;

    public $transaction;
    public $oldData;
    public $isDeleted;

    /**
     * Create a new event instance.
     */
    public function __construct(Transaction $transaction, ?array $oldData = null, bool $isDeleted = false)
    {
        $this->transaction = $transaction;
        $this->oldData = $oldData;
        $this->isDeleted = $isDeleted;
    }
}
