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
        Schema::create('ai_habit_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type', 20); // daily, monthly, yearly
            $table->date('analysis_date');
            $table->string('period_range', 100); // e.g. "15/07/2026", "Tháng 07/2026"
            $table->decimal('baseline_amount', 18, 2);
            $table->decimal('actual_amount', 18, 2);
            $table->decimal('diff_amount', 18, 2);
            $table->decimal('percent_change', 8, 2);
            $table->string('status', 30); // normal, overspending, saving
            $table->text('ai_insight')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            // Unique constraint to prevent duplicate analysis for the same user, type, and date
            $table->unique(['user_id', 'type', 'analysis_date']);

            // Indexes
            $table->index('user_id');
            $table->index('analysis_date');

            // Foreign key
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_habit_analyses');
    }
};
