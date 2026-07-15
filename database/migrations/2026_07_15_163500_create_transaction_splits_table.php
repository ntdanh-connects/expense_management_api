<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transaction_splits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->uuid('wallet_id');
            $table->decimal('amount', 18, 2);
            $table->decimal('amount_in_user_currency', 18, 2);
            $table->decimal('exchange_rate', 18, 6);
            $table->timestamps();

            // Indexes and unique constraints
            $table->unique(['transaction_id', 'wallet_id']);
            $table->index('transaction_id');
            $table->index('wallet_id');

            // Foreign keys
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('restrict');
        });

        // Add Check Constraints
        DB::statement('ALTER TABLE transaction_splits ADD CONSTRAINT chk_split_amount CHECK (amount > 0)');
        DB::statement('ALTER TABLE transaction_splits ADD CONSTRAINT chk_split_amount_user CHECK (amount_in_user_currency > 0)');
        DB::statement('ALTER TABLE transaction_splits ADD CONSTRAINT chk_split_rate CHECK (exchange_rate > 0)');

        // Data migration for existing transactions
        DB::table('transactions')
            ->whereNotNull('wallet_id')
            ->whereNotNull('amount')
            ->orderBy('id')
            ->chunk(500, function ($transactions) {
                $splits = [];
                foreach ($transactions as $tx) {
                    // Check if already migrated (idempotent check)
                    $exists = DB::table('transaction_splits')
                        ->where('transaction_id', $tx->id)
                        ->where('wallet_id', $tx->wallet_id)
                        ->exists();

                    if (!$exists) {
                        $splits[] = [
                            'id' => (string) Str::uuid(),
                            'transaction_id' => $tx->id,
                            'wallet_id' => $tx->wallet_id,
                            'amount' => $tx->amount,
                            'amount_in_user_currency' => $tx->amount_in_user_currency ?? $tx->amount,
                            'exchange_rate' => $tx->exchange_rate ?? 1.0,
                            'created_at' => $tx->created_at ?? now(),
                            'updated_at' => $tx->updated_at ?? now(),
                        ];
                    }
                }

                if (!empty($splits)) {
                    DB::table('transaction_splits')->insert($splits);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_splits');
    }
};
