<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\RecurringRule;

class DefaultReceivingWalletTest extends TestCase
{
    use DatabaseTransactions;

    protected $token;
    protected $userId;
    protected $userIdentifier;
    protected $walletId;

    protected function setUp(): void
    {
        parent::setUp();

        // Đăng nhập user chính
        $auth = $this->authenticateUser('sender@example.com', 'Sender User', 'USR123456');
        $this->token = $auth['token'];
        $this->userId = $auth['user_id'];
        $this->userIdentifier = 'USR123456';

        // Tạo ví chính của user gửi
        $this->walletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $this->walletId,
            'user_id' => $this->userId,
            'name' => 'Ví Gửi Tiền',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id' => $this->walletId,
            'available_balance' => 1000000.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // Mock tỷ giá và external services
        \Illuminate\Support\Facades\Cache::forget('latest_exchange_rates');
        \Illuminate\Support\Facades\Http::fake([
            '*frankfurter.*' => \Illuminate\Support\Facades\Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'rates' => ['VND' => 25000.0, 'USD' => 1.0]
            ], 200),
        ]);
    }

    protected function authenticateUser(string $email, string $fullName, string $identifier)
    {
        $userId = (string) Str::uuid7();

        DB::table('users')->insert([
            'user_id' => $userId,
            'email' => $email,
            'status' => 'active',
            'identifier' => $identifier,
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
            'full_name' => $fullName,
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
     * Test API: Thiết lập ví mặc định nhận tiền thành công
     */
    public function test_api_can_set_default_receiving_wallet()
    {
        // Tạo 2 ví của sender (một bank, một ewallet)
        $walletBank = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $walletBank,
            'user_id' => $this->userId,
            'name' => 'Ví MBBank',
            'type' => 'bank',
            'currency_code' => 'VND',
            'created_at' => now()
        ]);

        $walletEWallet = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $walletEWallet,
            'user_id' => $this->userId,
            'name' => 'Ví MoMo',
            'type' => 'ewallet',
            'currency_code' => 'VND',
            'created_at' => now()
        ]);

        // Đặt MBBank làm mặc định
        $response = $this->postJson("/api/wallets/{$walletBank}/set-default-receiving", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $this->assertEquals(1, DB::table('wallets')->where('id', $walletBank)->value('is_default_receiving'));
        $this->assertEquals(0, DB::table('wallets')->where('id', $walletEWallet)->value('is_default_receiving'));

        // Chuyển MoMo làm mặc định
        $response2 = $this->postJson("/api/wallets/{$walletEWallet}/set-default-receiving", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response2->assertStatus(200);

        $this->assertEquals(0, DB::table('wallets')->where('id', $walletBank)->value('is_default_receiving'));
        $this->assertEquals(1, DB::table('wallets')->where('id', $walletEWallet)->value('is_default_receiving'));
    }

    /**
     * Test API validation: Không được đặt ví cash làm mặc định
     */
    public function test_api_cannot_set_cash_wallet_as_default_receiving()
    {
        $walletCash = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $walletCash,
            'user_id' => $this->userId,
            'name' => 'Ví Tiền Mặt',
            'type' => 'cash',
            'currency_code' => 'VND',
            'created_at' => now()
        ]);

        $response = $this->postJson("/api/wallets/{$walletCash}/set-default-receiving", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('status', 'error');
    }

    /**
     * Test chuyển khoản QR P2P tự động định tuyến vào ví mặc định nhận tiền
     */
    public function test_qr_transfer_routes_to_default_receiving_wallet()
    {
        // 1. Tạo recipient user
        $recipientAuth = $this->authenticateUser('recipient@example.com', 'Recipient User', 'USR888888');
        $recipientId = $recipientAuth['user_id'];

        // Tạo 2 ví VND cho recipient (ví MBBank và ví ZaloPay)
        $walletRec1 = (string) Str::uuid7(); // MBBank
        DB::table('wallets')->insert([
            'id' => $walletRec1,
            'user_id' => $recipientId,
            'name' => 'Ví MBBank Recipient',
            'type' => 'bank',
            'currency_code' => 'VND',
            'created_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $walletRec1,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        $walletRec2 = (string) Str::uuid7(); // ZaloPay
        DB::table('wallets')->insert([
            'id' => $walletRec2,
            'user_id' => $recipientId,
            'name' => 'Ví ZaloPay Recipient',
            'type' => 'ewallet',
            'currency_code' => 'VND',
            'is_default_receiving' => true, // MBBank sẽ là mặc định nhận tiền
            'created_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $walletRec2,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // 2. Chuyển khoản QR không truyền to_wallet_id
        $response = $this->postJson('/api/qr/transfer', [
            'from_wallet_id' => $this->walletId,
            'payee_type' => 'internal',
            'payee_user_id' => $recipientId,
            'amount' => 75000.00,
            'notes' => 'Chuyển tiền QR đến ví mặc định'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);

        // Kiểm tra tiền cộng vào ví mặc định (walletRec2)
        $balance1 = DB::table('wallet_balances')->where('wallet_id', $walletRec1)->value('available_balance');
        $balance2 = DB::table('wallet_balances')->where('wallet_id', $walletRec2)->value('available_balance');

        $this->assertEquals(0.00, (float)$balance1);
        $this->assertEquals(75000.00, (float)$balance2);

        // Kiểm tra giao dịch nhận tiền đã được ghi nhận cho ví mặc định
        $this->assertDatabaseHas('transactions', [
            'user_id' => $recipientId,
            'wallet_id' => $walletRec2,
            'type' => 'income',
            'amount' => 75000.00
        ]);
    }

    /**
     * Test tạo giao dịch thủ công qua TransactionService định tuyến vào ví mặc định nhận tiền
     */
    public function test_manual_p2p_transaction_routes_to_default_receiving_wallet()
    {
        // 1. Tạo recipient user
        $recipientAuth = $this->authenticateUser('recipient2@example.com', 'Recipient 2', 'USR777777');
        $recipientId = $recipientAuth['user_id'];

        $walletRec1 = (string) Str::uuid7(); // MBBank
        DB::table('wallets')->insert([
            'id' => $walletRec1,
            'user_id' => $recipientId,
            'name' => 'Ví MBBank Recipient 2',
            'type' => 'bank',
            'currency_code' => 'VND',
            'created_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $walletRec1,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        $walletRec2 = (string) Str::uuid7(); // MoMo
        DB::table('wallets')->insert([
            'id' => $walletRec2,
            'user_id' => $recipientId,
            'name' => 'Ví MoMo Recipient 2',
            'type' => 'ewallet',
            'currency_code' => 'VND',
            'is_default_receiving' => true, // MoMo là ví mặc định nhận tiền
            'created_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $walletRec2,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // Tạo saved_payee của sender trỏ tới recipient2
        $payeeId = (string) Str::uuid7();
        DB::table('saved_payees')->insert([
            'id' => $payeeId,
            'user_id' => $this->userId,
            'payee_type' => 'internal',
            'payee_user_id' => $recipientId,
            'identifier' => 'USR777777',
            'payee_name' => 'Recipient 2',
            'created_at' => now()
        ]);

        // Tạo category cho chi tiêu
        $categoryId = (string) Str::uuid7();
        DB::table('categories')->insert([
            'id' => $categoryId,
            'user_id' => null,
            'type' => 'expense',
            'name' => 'Ăn uống',
            'is_default' => true,
            'created_at' => now()
        ]);

        // 2. Sender tạo giao dịch thủ công qua API
        $response = $this->postJson('/api/transactions', [
            'wallet_id' => $this->walletId,
            'category_id' => $categoryId,
            'type' => 'expense',
            'amount' => 120000.00,
            'title' => 'Trả tiền nước ép',
            'payee_id' => $payeeId
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);

        // Kiểm tra tiền đổ vào ví mặc định $walletRec2
        $balance1 = DB::table('wallet_balances')->where('wallet_id', $walletRec1)->value('available_balance');
        $balance2 = DB::table('wallet_balances')->where('wallet_id', $walletRec2)->value('available_balance');

        $this->assertEquals(0.00, (float)$balance1);
        $this->assertEquals(120000.00, (float)$balance2);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $recipientId,
            'wallet_id' => $walletRec2,
            'type' => 'income',
            'amount' => 120000.00
        ]);
    }

    /**
     * Test giao dịch định kỳ qua RecurringTransactionService định tuyến vào ví mặc định nhận tiền
     */
    public function test_recurring_p2p_transaction_routes_to_default_receiving_wallet()
    {
        Notification::fake();
        Carbon::setTestNow('2026-06-08 10:00:00');

        // 1. Tạo recipient user
        $recipientAuth = $this->authenticateUser('recipient3@example.com', 'Recipient 3', 'USR666666');
        $recipientId = $recipientAuth['user_id'];

        $walletRec1 = (string) Str::uuid7(); // MBBank
        DB::table('wallets')->insert([
            'id' => $walletRec1,
            'user_id' => $recipientId,
            'name' => 'Ví MBBank Recipient 3',
            'type' => 'bank',
            'currency_code' => 'VND',
            'created_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $walletRec1,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        $walletRec2 = (string) Str::uuid7(); // ZaloPay
        DB::table('wallets')->insert([
            'id' => $walletRec2,
            'user_id' => $recipientId,
            'name' => 'Ví ZaloPay Recipient 3',
            'type' => 'ewallet',
            'currency_code' => 'VND',
            'is_default_receiving' => true, // ZaloPay là mặc định nhận tiền
            'created_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $walletRec2,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // Tạo saved_payee
        $payeeId = (string) Str::uuid7();
        DB::table('saved_payees')->insert([
            'id' => $payeeId,
            'user_id' => $this->userId,
            'payee_type' => 'internal',
            'payee_user_id' => $recipientId,
            'identifier' => 'USR666666',
            'payee_name' => 'Recipient 3',
            'created_at' => now()
        ]);

        // Tạo category
        $categoryId = (string) Str::uuid7();
        DB::table('categories')->insert([
            'id' => $categoryId,
            'user_id' => null,
            'type' => 'expense',
            'name' => 'Ăn uống',
            'is_default' => true,
            'created_at' => now()
        ]);

        // Tạo recurring rule
        $rule = RecurringRule::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $categoryId,
            'payee_id' => $payeeId,
            'type' => 'expense',
            'amount' => 50000.00,
            'title' => 'Chuyển khoản định kỳ cho Recipient 3',
            'frequency' => 'daily',
            'interval_value' => 1,
            'start_date' => Carbon::parse('2026-06-08 10:00:00'),
            'next_run_at' => Carbon::parse('2026-06-08 10:00:00'),
            'is_active' => true
        ]);

        // Chạy cron xử lý định kỳ
        $this->artisan('recurring:process')->assertExitCode(0);

        // Kiểm tra tiền đổ vào ví mặc định $walletRec2
        $balance1 = DB::table('wallet_balances')->where('wallet_id', $walletRec1)->value('available_balance');
        $balance2 = DB::table('wallet_balances')->where('wallet_id', $walletRec2)->value('available_balance');

        $this->assertEquals(0.00, (float)$balance1);
        $this->assertEquals(50000.00, (float)$balance2);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $recipientId,
            'wallet_id' => $walletRec2,
            'type' => 'income',
            'amount' => 50000.00
        ]);

        Carbon::setTestNow();
    }
}
