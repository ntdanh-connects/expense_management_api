<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CategoryAiTest extends TestCase
{
    use DatabaseTransactions;

    protected $token;
    protected $userId;
    protected $parentCategoryId;
    protected $subCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo và đăng nhập User
        $auth = $this->authenticateUser();
        $this->token = $auth['token'];
        $this->userId = $auth['user_id'];

        // 2. Tạo danh mục cha hệ thống/mặc định
        $this->parentCategoryId = (string) Str::uuid7();
        DB::table('categories')->insert([
            'id' => $this->parentCategoryId,
            'user_id' => null,
            'parent_id' => null,
            'type' => 'expense',
            'name' => 'Chi tiêu - sinh hoạt',
            'is_default' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 3. Tạo danh mục con hệ thống/mặc định
        $this->subCategoryId = (string) Str::uuid7();
        DB::table('categories')->insert([
            'id' => $this->subCategoryId,
            'user_id' => null,
            'parent_id' => $this->parentCategoryId,
            'type' => 'expense',
            'name' => 'Ăn uống',
            'is_default' => true,
            'sort_order' => 1,
            'created_at' => now(),
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

        return ['token' => $token, 'user_id' => $userId];
    }

    /**
     * Test phân loại danh mục thành công
     */
    public function test_classify_category_success()
    {
        // Mock Gemini API
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode(['category_id' => $this->subCategoryId])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->postJson('/api/ai/classify-category', [
            'title' => 'Ăn trưa',
            'notes' => 'Bún chả 45k',
            'type' => 'expense'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.category_id', $this->subCategoryId);
    }

    /**
     * Test phân loại không khớp danh mục nào
     */
    public function test_classify_category_no_match()
    {
        // Mock Gemini API
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode(['category_id' => null])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->postJson('/api/ai/classify-category', [
            'title' => 'Không rõ',
            'notes' => 'abcxyz',
            'type' => 'expense'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.category_id', null);
    }

    /**
     * Test yêu cầu authentication
     */
    public function test_classify_category_requires_authentication()
    {
        $response = $this->postJson('/api/ai/classify-category', [
            'title' => 'Ăn trưa',
            'notes' => 'Bún chả 45k',
            'type' => 'expense'
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test giao dịch Sandbox nạp vào tự động phân loại bằng AI
     */
    public function test_sandbox_simulate_transfer_auto_classifies_via_gemini()
    {
        // 1. Tạo một ví để nhận tiền
        $walletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $walletId,
            'user_id' => $this->userId,
            'name' => 'Ví VCB Test',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id' => $walletId,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // Tạo danh mục thu nhập cho test
        $incomeCategoryId = (string) Str::uuid7();
        DB::table('categories')->insert([
            'id' => $incomeCategoryId,
            'user_id' => null,
            'parent_id' => null,
            'type' => 'income',
            'name' => 'Thu nhập khác',
            'is_default' => true,
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Mock Gemini API
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode(['category_id' => $incomeCategoryId])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        // 3. Giả lập nhận tiền từ Sandbox (notes là "tiền xăng")
        $response = $this->postJson('/api/sandbox/simulate-transfer', [
            'wallet_id' => $walletId,
            'amount' => 150000,
            'sender_name' => 'NGUYEN VAN B',
            'notes' => 'giao dịch tiền xăng gửi bạn'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        
        // 4. Kiểm tra trong Database xem transaction được tạo ra có category_id là $incomeCategoryId hay không!
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->userId,
            'wallet_id' => $walletId,
            'notes' => 'giao dịch tiền xăng gửi bạn',
            'category_id' => $incomeCategoryId
        ]);
    }
}
