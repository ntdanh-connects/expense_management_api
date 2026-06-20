<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SandboxSimulateTest extends TestCase
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

        // 2. Tạo một ví mặc định VND
        $this->walletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $this->walletId,
            'user_id' => $this->userId,
            'name' => 'Ví MBBank Test',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id' => $this->walletId,
            'available_balance' => 100000.00,
            'version' => 1,
            'updated_at' => now()
        ]);
    }

    protected function authenticateUser()
    {
        $userId = (string) Str::uuid7();

        DB::table('users')->insert([
            'user_id' => $userId,
            'email' => 'sandbox_test_' . uniqid() . '@example.com',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('user_preferences')->insert([
            'user_id' => $userId,
            'language' => 'vi',
            'theme' => 'light',
            'currency' => 'VND',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'financial_start_day' => 1,
            'created_at' => now()
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => $userId,
            'full_name' => 'Sandbox Tester',
            'created_at' => now()
        ]);

        $token = Str::random(60);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'refresh_token_hash' => hash('sha256', 'refresh'),
            'access_token_hash' => hash('sha256', $token),
            'access_token_expired_at' => now()->addHour(),
            'device_type' => 'web',
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expired_at' => now()->addDays(30),
            'created_at' => now()
        ]);

        return ['token' => $token, 'user_id' => $userId];
    }

    /**
     * Test 1: Giả lập chuyển tiền thành công với tham số hợp lệ
     */
    public function test_simulate_transfer_success()
    {
        $response = $this->postJson('/api/sandbox/simulate-transfer', [
            'wallet_id' => $this->walletId,
            'amount' => 500000.00,
            'sender_name' => 'NGUYEN VAN B',
            'notes' => 'Chuyen tien an trua'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'transaction',
                'available_balance'
            ]
        ]);

        // Kiểm tra số dư ví mới phải bằng 100,000 + 500,000 = 600,000 VND
        $this->assertEquals(600000.00, $response->json('data.available_balance'));

        // Kiểm tra cơ sở dữ liệu
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'type' => 'income',
            'amount' => 500000.00,
            'title' => 'Nhận tiền từ NGUYEN VAN B',
            'notes' => 'Chuyen tien an trua',
            'source_type' => 'import'
        ]);

        $this->assertDatabaseHas('wallet_balances', [
            'wallet_id' => $this->walletId,
            'available_balance' => 600000.00
        ]);
    }

    /**
     * Test 2: Thất bại khi ví nhận thuộc sở hữu của người dùng khác
     */
    public function test_simulate_transfer_unauthorized_wallet()
    {
        // Tạo một user khác và ví của họ
        $otherAuth = $this->authenticateUser();
        $otherWalletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $otherWalletId,
            'user_id' => $otherAuth['user_id'],
            'name' => 'Ví Người Khác',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now()
        ]);

        // Gửi request giả lập chuyển khoản dùng Token của User hiện tại nhưng ví của User khác
        $response = $this->postJson('/api/sandbox/simulate-transfer', [
            'wallet_id' => $otherWalletId,
            'amount' => 500000.00
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('status', 'error');
    }

    /**
     * Test 3: Thất bại khi truyền các tham số không hợp lệ
     */
    public function test_simulate_transfer_validation_fails()
    {
        // 1. Số tiền không hợp lệ (âm)
        $response = $this->postJson('/api/sandbox/simulate-transfer', [
            'wallet_id' => $this->walletId,
            'amount' => -100.00
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(400);

        // 2. Wallet ID không phải UUID hợp lệ
        $response2 = $this->postJson('/api/sandbox/simulate-transfer', [
            'wallet_id' => 'not-a-uuid',
            'amount' => 10000.00
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response2->assertStatus(400);
    }

    /**
     * Test 4: Thất bại khi giả lập chuyển khoản vào ví tiền mặt (cash)
     */
    public function test_simulate_transfer_to_cash_wallet_fails()
    {
        // 1. Tạo một ví tiền mặt (cash) của user hiện tại
        $cashWalletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $cashWalletId,
            'user_id' => $this->userId,
            'name' => 'Ví Tiền Mặt Test',
            'type' => 'cash',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id' => $cashWalletId,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // 2. Gửi request giả lập nạp tiền vào ví tiền mặt
        $response = $this->postJson('/api/sandbox/simulate-transfer', [
            'wallet_id' => $cashWalletId,
            'amount' => 500000.00
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        // 3. Phải trả về mã lỗi 400 Bad Request
        $response->assertStatus(400);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('message', 'Chỉ hỗ trợ giả lập nhận tiền từ Sandbox vào ví ngân hàng hoặc ví điện tử.');
    }
}
