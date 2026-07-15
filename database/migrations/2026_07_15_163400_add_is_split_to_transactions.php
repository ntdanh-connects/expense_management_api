<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_split')->default(false);
            $table->decimal('amount', 18, 2)->nullable()->change();
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_transaction_split_consistency CHECK (
            (is_split = false AND wallet_id IS NOT NULL AND amount IS NOT NULL)
            OR
            (is_split = true AND wallet_id IS NULL AND amount IS NULL)
        )');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS chk_transaction_split_consistency');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('is_split');
            $table->decimal('amount', 18, 2)->nullable(false)->change();
        });
    }
};
