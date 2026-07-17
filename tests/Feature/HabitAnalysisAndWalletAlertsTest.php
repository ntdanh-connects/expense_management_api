<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Services\AIHabitAnalysisService;

class HabitAnalysisAndWalletAlertsTest extends TestCase
{
    use DatabaseTransactions;

    protected $token;
    protected $userId;
    protected $categoryId;
    protected $walletIdA;
    protected $walletIdB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo và đăng nhập User
        $auth = $this->authenticateUser();
        $this->token = $auth['token'];
        $this->userId = $auth['user_id'];

        // 2. Tạo danh mục chi tiêu
        $this->categoryId = (string) Str::uuid7();
        DB::table('categories')->insert([
            'id' => $this->categoryId,
            'user_id' => null,
            'parent_id' => null,
            'type' => 'expense',
            'name' => 'Ăn uống',
            'is_default' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 3. Tạo 2 Ví thử nghiệm
        $this->walletIdA = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $this->walletIdA,
            'user_id' => $this->userId,
            'name' => 'Ví A',
            'type' => 'cash',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $this->walletIdA,
            'available_balance' => 500000.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        $this->walletIdB = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $this->walletIdB,
            'user_id' => $this->userId,
            'name' => 'Ví B',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $this->walletIdB,
            'available_balance' => 300000.00,
            'version' => 1,
            'updated_at' => now()
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

        return ['user_id' => $userId, 'token' => $token];
    }

    /**
     * Test case 1: Sửa giao dịch Split (Nhiều ví) thành Non-split (Đơn ví)
     */
    public function test_split_to_non_split_transaction_updates()
    {
        // 1. Tạo giao dịch split
        $splits = [
            ['wallet_id' => $this->walletIdA, 'amount' => 100000.00],
            ['wallet_id' => $this->walletIdB, 'amount' => 50000.00],
        ];

        $response = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 150000.00,
            'title' => 'Ăn tối nhóm',
            'category_id' => $this->categoryId,
            'splits' => $splits,
            'currency_code' => 'VND',
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        $transactionId = $response->json('data.id');

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'is_split' => true,
            'wallet_id' => null,
            'amount' => null,
        ]);
        $this->assertEquals(2, DB::table('transaction_splits')->where('transaction_id', $transactionId)->count());

        // Số dư ban đầu: Ví A = 500k -> 400k; Ví B = 300k -> 250k
        $this->assertEquals(400000.00, DB::table('wallet_balances')->where('wallet_id', $this->walletIdA)->value('available_balance'));
        $this->assertEquals(250000.00, DB::table('wallet_balances')->where('wallet_id', $this->walletIdB)->value('available_balance'));

        // 2. Cập nhật giao dịch thành đơn ví (Ví A)
        $updateResponse = $this->putJson("/api/transactions/{$transactionId}", [
            'type' => 'expense',
            'amount' => 150000.00,
            'title' => 'Ăn tối nhóm - Đổi đơn ví',
            'category_id' => $this->categoryId,
            'wallet_id' => $this->walletIdA,
            'currency_code' => 'VND',
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $updateResponse->assertStatus(200);

        // Assert: is_split = false, wallet_id và amount được set lại đúng
        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'is_split' => false,
            'wallet_id' => $this->walletIdA,
            'amount' => 150000.00,
        ]);

        // Assert: Bảng transaction_splits phải bị xóa hoàn toàn dòng liên quan
        $this->assertEquals(0, DB::table('transaction_splits')->where('transaction_id', $transactionId)->count());

        // Assert: Số dư Ví A phải trả lại 100k cũ và trừ 150k mới (500k - 150k = 350k)
        $this->assertEquals(350000.00, DB::table('wallet_balances')->where('wallet_id', $this->walletIdA)->value('available_balance'));
        // Assert: Số dư Ví B phải trả lại 50k cũ (250k + 50k = 300k)
        $this->assertEquals(300000.00, DB::table('wallet_balances')->where('wallet_id', $this->walletIdB)->value('available_balance'));
    }

    /**
     * Test case 2: Sửa giao dịch Non-split (Đơn ví) thành Split (Nhiều ví)
     */
    public function test_non_split_to_split_transaction_updates()
    {
        // 1. Tạo giao dịch đơn ví A
        $response = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 150000.00,
            'title' => 'Mua sách',
            'category_id' => $this->categoryId,
            'wallet_id' => $this->walletIdA,
            'currency_code' => 'VND',
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        $transactionId = $response->json('data.id');

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'is_split' => false,
            'wallet_id' => $this->walletIdA,
            'amount' => 150000.00,
        ]);

        // Ví A: 500k -> 350k
        $this->assertEquals(350000.00, DB::table('wallet_balances')->where('wallet_id', $this->walletIdA)->value('available_balance'));

        // 2. Cập nhật thành giao dịch Split (Ví A 100k + Ví B 50k)
        $splits = [
            ['wallet_id' => $this->walletIdA, 'amount' => 100000.00],
            ['wallet_id' => $this->walletIdB, 'amount' => 50000.00],
        ];

        $updateResponse = $this->putJson("/api/transactions/{$transactionId}", [
            'type' => 'expense',
            'amount' => 150000.00,
            'title' => 'Mua sách - Đổi kết hợp ví',
            'category_id' => $this->categoryId,
            'splits' => $splits,
            'currency_code' => 'VND',
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $updateResponse->assertStatus(200);

        // Assert: is_split = true, wallet_id và amount = null
        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'is_split' => true,
            'wallet_id' => null,
            'amount' => null,
        ]);

        // Assert: Bảng transaction_splits xuất hiện đúng 2 bản ghi
        $this->assertEquals(2, DB::table('transaction_splits')->where('transaction_id', $transactionId)->count());

        // Assert: Số dư Ví A được trả lại 150k và trừ 100k mới (500k - 100k = 400k)
        $this->assertEquals(400000.00, DB::table('wallet_balances')->where('wallet_id', $this->walletIdA)->value('available_balance'));
        // Assert: Số dư Ví B bị trừ 50k mới (300k - 50k = 250k)
        $this->assertEquals(250000.00, DB::table('wallet_balances')->where('wallet_id', $this->walletIdB)->value('available_balance'));
    }

    /**
     * Test case 3: Cảnh báo số dư tối thiểu khi Bật/Tắt alert
     */
    public function test_wallet_minimum_balance_alert_and_toggle_resets()
    {
        // 1. Tạo ví có cảnh báo hạn mức
        $walletId = (string) Str::uuid7();
        $response = $this->postJson('/api/wallets', [
            'id' => $walletId,
            'name' => 'Ví Test Cảnh Báo',
            'type' => 'cash',
            'currency_code' => 'VND',
            'available_balance' => 150000.00, // Nhỏ hơn hạn mức tối thiểu
            'minimum_balance' => 200000.00,
            'is_minimum_balance_alert_enabled' => true,
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        
        // Cập nhật giả lập last_alert_sent_at khác null (đã bắn cảnh báo trước đó)
        DB::table('wallets')->where('id', $walletId)->update([
            'last_alert_sent_at' => now()->subDay()
        ]);

        $this->assertNotNull(DB::table('wallets')->where('id', $walletId)->value('last_alert_sent_at'));

        // 2. Cập nhật tắt cảnh báo
        $updateResponse = $this->putJson("/api/wallets/{$walletId}", [
            'name' => 'Ví Test Cảnh Báo',
            'type' => 'cash',
            'is_minimum_balance_alert_enabled' => false,
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);
        $updateResponse->assertStatus(200);

        // 3. Cập nhật bật lại cảnh báo -> Phải reset last_alert_sent_at = null
        $enableResponse = $this->putJson("/api/wallets/{$walletId}", [
            'name' => 'Ví Test Cảnh Báo',
            'type' => 'cash',
            'is_minimum_balance_alert_enabled' => true,
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);
        $enableResponse->assertStatus(200);

        // Assert: last_alert_sent_at phải được reset về null để bắt đầu chu kỳ theo dõi mới
        $this->assertNull(DB::table('wallets')->where('id', $walletId)->value('last_alert_sent_at'));
    }

    /**
     * Test case 4: Tác vụ Gemini AI thử lại (Retry) khi gặp lỗi 429
     */
    public function test_ai_habit_analysis_gemini_retry_behavior()
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => 'Rate limit exceeded'], 429)
                ->push(['error' => 'Rate limit exceeded'], 429)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    ['text' => 'Báo cáo: Hôm nay chi tiêu rất tốt.']
                                ]
                            ]
                        ]
                    ]
                ], 200),
        ]);

        $service = app(AIHabitAnalysisService::class);
        
        // Gọi hàm phân tích thói quen ngày (hôm nay có chi tiêu để kích hoạt gọi AI)
        $txId = (string) Str::uuid7();
        DB::table('transactions')->insert([
            'id' => $txId,
            'user_id' => $this->userId,
            'wallet_id' => $this->walletIdA,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'status' => 'completed',
            'amount' => 100000.00,
            'currency_code' => 'VND',
            'exchange_rate' => 1.0,
            'amount_in_user_currency' => 100000.00,
            'title' => 'Chi tiêu hôm nay',
            'transaction_date' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $service->generateDailyAnalysis($this->userId, now());

        // Assert: Bảng ghi nhận phân tích thành công do đã thử lại lần thứ 3 thành công
        $this->assertDatabaseHas('ai_habit_analyses', [
            'user_id' => $this->userId,
            'type' => 'daily',
            'ai_insight' => 'Báo cáo: Hôm nay chi tiêu rất tốt.'
        ]);
    }
}
