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
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('minimum_balance', 18, 2)->nullable()->default(null);
            $table->boolean('is_minimum_balance_alert_enabled')->default(true);
            $table->timestampTz('last_alert_sent_at')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn([
                'minimum_balance',
                'is_minimum_balance_alert_enabled',
                'last_alert_sent_at',
            ]);
        });
    }
};
