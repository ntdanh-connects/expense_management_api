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
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('payee_id')->nullable();
            $table->foreign('payee_id')->references('id')->on('saved_payees')->onDelete('set null');
        });

        Schema::table('recurring_rules', function (Blueprint $table) {
            $table->uuid('payee_id')->nullable();
            $table->foreign('payee_id')->references('id')->on('saved_payees')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['payee_id']);
            $table->dropColumn('payee_id');
        });

        Schema::table('recurring_rules', function (Blueprint $table) {
            $table->dropForeign(['payee_id']);
            $table->dropColumn('payee_id');
        });
    }
};
