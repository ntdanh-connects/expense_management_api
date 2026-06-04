<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Thêm cột amount_in_user_currency vào bảng transactions
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('amount_in_user_currency', 18, 2)->nullable()->after('exchange_rate');
        });

        // 2. Điền dữ liệu cũ (Data Backfill)
        $fallbackRates = [
            'USD' => 1.0,
            'VND' => 25400.0,
            'EUR' => 0.92,
            'GBP' => 0.78,
            'JPY' => 156.0,
        ];

        // Lấy tất cả giao dịch hiện tại cùng thông tin ví và cấu hình tiền tệ của user
        $transactions = DB::table('transactions')
            ->join('wallets', 'transactions.wallet_id', '=', 'wallets.id')
            ->leftJoin('user_preferences', 'transactions.user_id', '=', 'user_preferences.user_id')
            ->select(
                'transactions.id',
                'transactions.amount',
                'transactions.currency_code as tx_currency',
                'transactions.exchange_rate as tx_rate',
                'wallets.currency_code as wallet_currency',
                'user_preferences.currency as pref_currency'
            )
            ->get();

        foreach ($transactions as $tx) {
            $prefCurrency = strtoupper(trim($tx->pref_currency ?? 'VND'));
            $txCurrency = strtoupper(trim($tx->tx_currency));
            $walletCurrency = strtoupper(trim($tx->wallet_currency));
            $amount = (float) $tx->amount;
            $rate = (float) ($tx->tx_rate ?? 1.000000);

            $amountInUserCurrency = 0.00;

            if ($txCurrency === $prefCurrency) {
                // Trường hợp 1: Tiền tệ giao dịch trùng với tiền tệ hiển thị của user
                $amountInUserCurrency = $amount;
            } elseif ($walletCurrency === $prefCurrency) {
                // Trường hợp 2: Tiền tệ ví trùng với tiền tệ hiển thị của user (dùng tỷ giá ví đã lưu)
                $amountInUserCurrency = (float) bcmul((string)$amount, sprintf('%.6f', $rate), 4);
            } else {
                // Trường hợp 3: Quy đổi chéo thông qua tỷ giá fallback
                $fromRate = $fallbackRates[$txCurrency] ?? 1.0;
                $toRate = $fallbackRates[$prefCurrency] ?? 25400.0;
                
                // Quy đổi chéo qua trung gian USD:
                // amount * (toRate / fromRate)
                $crossRate = $toRate / $fromRate;
                $amountInUserCurrency = (float) bcmul((string)$amount, sprintf('%.6f', $crossRate), 4);
            }

            // Cập nhật lại dòng tương ứng
            DB::table('transactions')
                ->where('id', $tx->id)
                ->update([
                    'amount_in_user_currency' => round($amountInUserCurrency, 2),
                    'updated_at' => now()
                ]);
        }

        // 3. Đổi cột amount_in_user_currency thành NOT NULL sau khi đã điền xong dữ liệu cũ
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('amount_in_user_currency', 18, 2)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('amount_in_user_currency');
        });
    }
};
