<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_rules', function (Blueprint $table) {
            $table->timestampTz('start_date')->nullable()->after('interval_value');
        });

        // Cập nhật start_date cho các rule hiện có bằng next_run_at
        \Illuminate\Support\Facades\DB::table('recurring_rules')
            ->whereNull('start_date')
            ->update([
                'start_date' => \Illuminate\Support\Facades\DB::raw('next_run_at')
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_rules', function (Blueprint $table) {
            $table->dropColumn('start_date');
        });
    }
};
