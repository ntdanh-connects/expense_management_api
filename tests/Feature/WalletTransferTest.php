<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletTransferTest extends TestCase
{
    use DatabaseTransactions;

    protected $token;
    protected $userId;
    protected $wallet1Id;
    protected $wallet2Id;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo và đăng nhập User
        $auth = $this->authenticateUser();
        $this->token = $auth['token'];
        $this->userId = $auth['user_id'];

        // 2. Tạo ví 1 (VND)
        $this->wallet1Id = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $this->wallet1Id,
            'user_id' => $this->userId,
            'name' => 'Ví Tiền Mặt VND',
            'type' => 'cash',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id' => $this->wallet1Id,
            'available_balance' => 500000.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // 3. Tạo ví 2 (USD)
        $this->wallet2Id = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $this->wallet2Id,
            'user_id' => $this->userId,
            'name' => 'Ví USD Saving',
            'type' => 'bank',
            'currency_code' => 'USD',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id' => $this->wallet2Id,
            'available_balance' => 100.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // Xóa cache tỷ giá trước đó để test chạy sạch
        \Illuminate\Support\Facades\Cache::forget('latest_exchange_rates');
        
        // Mock API tỷ giá ngoài (Frankfurter)
        \Illuminate\Support\Facades\Http::fake([
            '*frankfurter.*' => \Illuminate\Support\Facades\Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => '2026-06-04',
                'rates' => [
                    'VND' => 25000.0,
                    'USD' => 1.0,
                    'EUR' => 0.92,
                    'GBP' => 0.78,
                    'JPY' => 156.0,
                ]
            ], 200),
        ]);
    }

    protected function authenticateUser()
    {
        $userId = (string) Str::uuid7();

        DB::table('users')->insert([
            'user_id' => $userId,
            'email' => 'test_' . uniqid() . '@example.com',
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
            'full_name' => 'Test User',
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

    public function test_internal_wallet_transfer_creates_transactions_with_amount_in_user_currency()
    {
        // Chuyển 100,000 VND sang ví USD
        $response = $this->postJson('/api/wallets/transfer', [
            'from_wallet_id' => $this->wallet1Id,
            'to_wallet_id' => $this->wallet2Id,
            'amount' => 100000.00,
            'notes' => 'Chuyển tiền tiêu vặt',
            'timezone' => 'Asia/Ho_Chi_Minh'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        if ($response->status() !== 200) {
            dump($response->json());
        }

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'transfer_id',
                'expense_transaction_id',
                'income_transaction_id'
            ]
        ]);

        // Kiểm tra xem 2 giao dịch (expense, income) có được tạo thành công với amount_in_user_currency đầy đủ
        $data = $response->json('data');
        $expenseTxId = $data['expense_transaction_id'];
        $incomeTxId = $data['income_transaction_id'];

        $expenseTx = DB::table('transactions')->where('id', $expenseTxId)->first();
        $this->assertNotNull($expenseTx);
        $this->assertEquals('expense', $expenseTx->type);
        $this->assertEquals('transfer', $expenseTx->source_type);
        $this->assertEquals(100000.00, (float)$expenseTx->amount);
        $this->assertEquals('VND', $expenseTx->currency_code);
        // Do userCurrency = VND và ví nguồn là VND -> amount_in_user_currency phải bằng 100000.00
        $this->assertEquals(100000.00, (float)$expenseTx->amount_in_user_currency);

        $incomeTx = DB::table('transactions')->where('id', $incomeTxId)->first();
        $this->assertNotNull($incomeTx);
        $this->assertEquals('income', $incomeTx->type);
        $this->assertEquals('transfer', $incomeTx->source_type);
        // Tỷ giá VND -> USD là 0.00004 -> amount nhận được là 100000 * 0.00004 = 4.00 USD
        $this->assertEquals(4.00, (float)$incomeTx->amount);
        $this->assertEquals('USD', $incomeTx->currency_code);
        // Do userCurrency = VND và ví đích là USD -> amount_in_user_currency phải được quy đổi ngược từ USD sang VND: 4.00 * 25000 = 100000.00
        $this->assertEquals(100000.00, (float)$incomeTx->amount_in_user_currency);
    }

    public function test_get_transactions_with_cursor_pagination()
    {
        // 1. Tạo thêm vài giao dịch thủ công để phân trang
        for ($i = 1; $i <= 5; $i++) {
            DB::table('transactions')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $this->userId,
                'wallet_id' => $this->wallet1Id,
                'type' => 'expense',
                'amount' => 1000.00 * $i,
                'currency_code' => 'VND',
                'exchange_rate' => 1.0,
                'amount_in_user_currency' => 1000.00 * $i,
                'title' => 'Giao dịch test ' . $i,
                'transaction_date' => now()->subDays($i),
                'created_at' => now()->subDays($i),
                'updated_at' => now()->subDays($i)
            ]);
        }

        // 2. Gọi API lấy danh sách với phân trang 2 phần tử
        $response = $this->getJson('/api/transactions?per_page=2', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'data',
                'next_cursor',
                'next_page_url',
                'prev_cursor',
                'prev_page_url'
            ]
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data['data']);
        $this->assertNotNull($data['next_cursor']);

        // 3. Sử dụng next_cursor để lấy trang tiếp theo
        $nextCursor = $data['next_cursor'];
        $responseNext = $this->getJson('/api/transactions?per_page=2&cursor=' . $nextCursor, [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $responseNext->assertStatus(200);
        $dataNext = $responseNext->json('data');
        $this->assertCount(2, $dataNext['data']);
    }
}
