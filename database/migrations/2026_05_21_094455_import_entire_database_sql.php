<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sqlPath = database_path('expense_management.sql');

        if (!file_exists($sqlPath)) {
            throw new \Exception("Sếp ơi, chưa bỏ file expense_management.sql vào thư mục database/ kìa!");
        }

        // Đọc toàn bộ file SQL và bắn thẳng vào PostgreSQL
        $sql = file_get_contents($sqlPath);
        DB::unprepared($sql);
    }

    public function down(): void
    {
        // Khi cần rollback thì dọn sạch các bảng trong public schema
        $tables = DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
        foreach ($tables as $table) {
            DB::statement('DROP TABLE IF EXISTS ' . $table->tablename . ' CASCADE');
        }
    }
};