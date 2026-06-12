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
        Schema::create('saved_payees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('payee_type', 20); // 'internal' or 'external'
            $table->uuid('payee_user_id')->nullable();
            $table->string('identifier', 100); // app user code or bank account number
            $table->string('bank_code', 20)->nullable(); // Napas bank BIN
            $table->string('bank_name', 100)->nullable();
            $table->string('payee_name', 255);
            $table->string('nickname', 255)->nullable();
            $table->timestampTz('last_scanned_at')->useCurrent();
            $table->integer('scan_count')->default(1);
            $table->timestampsTz();

            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->foreign('payee_user_id')->references('user_id')->on('users')->onDelete('set null');
            
            $table->unique(['user_id', 'identifier', 'bank_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_payees');
    }
};
