<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared("
            CREATE OR REPLACE FUNCTION reset_wallet_alert_sent_at()
            RETURNS TRIGGER AS $$
            BEGIN
                UPDATE wallets 
                SET last_alert_sent_at = NULL 
                WHERE id = NEW.wallet_id 
                  AND minimum_balance IS NOT NULL 
                  AND NEW.available_balance >= minimum_balance;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_reset_wallet_alert_sent_at
            AFTER UPDATE OF available_balance ON wallet_balances
            FOR EACH ROW
            EXECUTE FUNCTION reset_wallet_alert_sent_at();
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_reset_wallet_alert_sent_at ON wallet_balances;
            DROP FUNCTION IF EXISTS reset_wallet_alert_sent_at();
        ");
    }
};
