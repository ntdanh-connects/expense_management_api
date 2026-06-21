<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name', 255);
            $table->decimal('target_amount', 15, 2);
            $table->decimal('current_amount', 15, 2)->default(0.00);
            $table->date('target_date')->nullable();
            $table->string('status', 50)->default('active'); // active, completed, cancelled
            $table->string('auto_save_frequency', 50)->nullable(); // daily, weekly, monthly, null
            $table->decimal('auto_save_amount', 15, 2)->nullable();
            $table->uuid('source_wallet_id')->nullable();
            $table->timestampsTz();

            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->foreign('source_wallet_id')->references('id')->on('wallets')->onDelete('set null');
        });

        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('savings_goal_id');
            $table->string('type', 20); // deposit, withdraw
            $table->decimal('amount', 15, 2);
            $table->uuid('source_wallet_id');
            $table->timestampTz('transaction_date')->useCurrent();
            $table->string('notes', 255)->nullable();
            $table->timestampsTz();

            $table->foreign('savings_goal_id')->references('id')->on('savings_goals')->onDelete('cascade');
            $table->foreign('source_wallet_id')->references('id')->on('wallets')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('savings_transactions');
        Schema::dropIfExists('savings_goals');
    }
};
