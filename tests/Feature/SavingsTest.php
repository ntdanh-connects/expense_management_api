<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SavingsTest extends TestCase
{
    use DatabaseTransactions;

    protected $token;
    protected $userId;
    protected $walletId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo và đăng nhập User
        $auth = $this->authenticateUser();
        $this->token = $auth['token'];
        $this->userId = $auth['user_id'];

        // 2. Tạo ví nguồn chính
        $this->walletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id'            => $this->walletId,
            'user_id'       => $this->userId,
            'name'          => 'VietinBank Test',
            'type'          => 'bank',
            'currency_code' => 'VND',
            'is_hidden'     => false,
            'created_at'    => now(),
            'updated_at'    => now()
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id'         => $this->walletId,
            'available_balance' => 10000000.00, // 10 triệu
            'version'           => 1,
            'updated_at'        => now()
        ]);
    }

    protected function authenticateUser()
    {
        $userId = (string) Str::uuid7();

        DB::table('users')->insert([
            'user_id'    => $userId,
            'email'      => 'test_' . uniqid() . '@example.com',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('user_preferences')->insert([
            'user_id'             => $userId,
            'language'            => 'vi',
            'theme'               => 'light',
            'currency'            => 'VND',
            'timezone'            => 'Asia/Ho_Chi_Minh',
            'financial_start_day' => 1,
            'created_at'          => now()
        ]);

        DB::table('user_profiles')->insert([
            'user_id'    => $userId,
            'full_name'  => 'Test User',
            'created_at' => now()
        ]);

        $token = Str::random(60);

        DB::table('user_sessions')->insert([
            'id'                      => (string) Str::uuid7(),
            'user_id'                 => $userId,
            'refresh_token_hash'      => hash('sha256', 'refresh'),
            'access_token_hash'       => hash('sha256', $token),
            'access_token_expired_at' => now()->addHour(),
            'device_type'             => 'web',
            'device_name'             => 'PHPUnit',
            'ip_address'              => '127.0.0.1',
            'user_agent'              => 'PHPUnit',
            'expired_at'              => now()->addDays(30),
            'created_at'              => now()
        ]);

        return ['token' => $token, 'user_id' => $userId];
    }

    public function test_create_savings_goal()
    {
        $response = $this->postJson('/api/savings', [
            'name'                => 'Mua xe mới',
            'target_amount'       => 50000000.00,
            'target_date'         => now()->addDays(30)->toDateString(),
            'auto_save_frequency' => 'daily',
            'auto_save_amount'    => 100000.00,
            'source_wallet_id'    => $this->walletId
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.name', 'Mua xe mới');
        
        $this->assertDatabaseHas('savings_goals', [
            'name'             => 'Mua xe mới',
            'target_amount'    => 50000000.00,
            'source_wallet_id' => $this->walletId
        ]);
    }

    public function test_deposit_and_withdraw_savings_goal()
    {
        // 1. Tạo goal trước
        $goalId = (string) Str::uuid7();
        DB::table('savings_goals')->insert([
            'id'               => $goalId,
            'user_id'          => $this->userId,
            'name'             => 'Mua Macbook',
            'target_amount'    => 30000000.00,
            'current_amount'   => 0.00,
            'source_wallet_id' => $this->walletId,
            'created_at'       => now(),
            'updated_at'       => now()
        ]);

        // 2. Deposit 500k
        $responseDep = $this->postJson("/api/savings/{$goalId}/deposit", [
            'amount'           => 500000.00,
            'source_wallet_id' => $this->walletId,
            'notes'            => 'Tích lũy tháng 6'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $responseDep->assertStatus(200);
        $responseDep->assertJsonPath('data.current_amount', '500000.00');

        // Kiểm tra ví nguồn bị trừ tiền
        $balance = DB::table('wallet_balances')->where('wallet_id', $this->walletId)->value('available_balance');
        $this->assertEquals(9500000.00, (float)$balance); // 10m - 500k = 9.5m

        // Kiểm tra có log trong savings_transactions
        $this->assertDatabaseHas('savings_transactions', [
            'savings_goal_id' => $goalId,
            'type'            => 'deposit',
            'amount'          => 500000.00
        ]);

        // Kiểm tra có log trong transactions chính
        $this->assertDatabaseHas('transactions', [
            'user_id'     => $this->userId,
            'wallet_id'   => $this->walletId,
            'type'        => 'expense',
            'amount'      => 500000.00,
            'source_type' => 'transfer',
            'source_id'   => $goalId
        ]);

        // 3. Withdraw 200k
        $responseWit = $this->postJson("/api/savings/{$goalId}/withdraw", [
            'amount'           => 200000.00,
            'source_wallet_id' => $this->walletId,
            'notes'            => 'Rút mua đồ ăn'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $responseWit->assertStatus(200);
        $responseWit->assertJsonPath('data.current_amount', '300000.00'); // 500k - 200k

        // Kiểm tra ví nguồn được cộng lại tiền
        $balance = DB::table('wallet_balances')->where('wallet_id', $this->walletId)->value('available_balance');
        $this->assertEquals(9700000.00, (float)$balance); // 9.5m + 200k = 9.7m
    }

    public function test_auto_accumulate_command()
    {
        $goalId = (string) Str::uuid7();
        DB::table('savings_goals')->insert([
            'id'                  => $goalId,
            'user_id'             => $this->userId,
            'name'                => 'Mua iPhone 16 Pro',
            'target_amount'       => 35000000.00,
            'current_amount'      => 0.00,
            'source_wallet_id'    => $this->walletId,
            'auto_save_frequency' => 'daily',
            'auto_save_amount'    => 200000.00,
            'status'              => 'active',
            'created_at'          => now(),
            'updated_at'          => now()
        ]);

        // Run command
        $this->artisan('savings:auto-accumulate')
            ->assertExitCode(0);

        // Check if money was transferred
        $goal = DB::table('savings_goals')->where('id', $goalId)->first();
        $this->assertEquals(200000.00, (float)$goal->current_amount);

        $balance = DB::table('wallet_balances')->where('wallet_id', $this->walletId)->value('available_balance');
        $this->assertEquals(9800000.00, (float)$balance); // 10m - 200k
    }
}
