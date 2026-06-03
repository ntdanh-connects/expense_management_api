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
            // Chỉ thêm nếu cột chưa tồn tại
            if (!Schema::hasColumn('wallets', 'currency_code')) {
                $table->string('currency_code', 10)->default('VND');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'timezone')) {
                $table->string('timezone', 100)->default('Asia/Ho_Chi_Minh');
            }
        });

        Schema::table('wallet_transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('wallet_transfers', 'timezone')) {
                $table->string('timezone', 100)->default('Asia/Ho_Chi_Minh');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (Schema::hasColumn('wallets', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });

        Schema::table('wallet_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('wallet_transfers', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
