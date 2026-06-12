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
        Schema::table('users', function (Blueprint $table) {
            $table->string('identifier')->nullable()->unique()->after('email');
        });

        // Populate existing users with a unique identifier
        $users = \Illuminate\Support\Facades\DB::table('users')->whereNull('identifier')->get();
        foreach ($users as $user) {
            $randomId = 'USR' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
            while (\Illuminate\Support\Facades\DB::table('users')->where('identifier', $randomId)->exists()) {
                $randomId = 'USR' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
            }
            \Illuminate\Support\Facades\DB::table('users')->where('user_id', $user->user_id)->update(['identifier' => $randomId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('identifier');
        });
    }
};
