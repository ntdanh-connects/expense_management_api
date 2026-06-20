<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BudgetWarningNotification;

class BudgetTest extends TestCase
{
    use DatabaseTransactions;

    protected $token;
    protected $userId;
    protected $categoryId;
    protected $walletId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo và đăng nhập User
        $auth = $this->authenticateUser();
        $this->token = $auth['token'];
        $this->userId = $auth['user_id'];

        // 2. Tạo một danh mục mặc định
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

        // 3. Tạo một ví mặc định
        $this->walletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $this->walletId,
            'user_id' => $this->userId,
            'name' => 'Ví Tiền Mặt',
            'type' => 'cash',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id' => $this->walletId,
            'available_balance' => 5000000.00,
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

        return ['token' => $token, 'user_id' => $userId];
    }

    /**
     * Test 1: Tạo và cập nhật ngân sách qua API
     */
    public function test_create_and_update_budget()
    {
        // Tạo ngân sách cho danh mục Ăn uống
        $response = $this->postJson('/api/budgets', [
            'category_id' => $this->categoryId,
            'limit_amount' => 1000000.00,
            'month' => 6,
            'year' => 2026
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('budgets', [
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'limit_amount' => 1000000.00,
            'month' => 6,
            'year' => 2026
        ]);

        // Cập nhật lại hạn mức
        $response = $this->postJson('/api/budgets', [
            'category_id' => $this->categoryId,
            'limit_amount' => 1200000.00,
            'month' => 6,
            'year' => 2026
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('budgets', [
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'limit_amount' => 1200000.00
        ]);
    }

    /**
     * Test 2: Lấy danh sách ngân sách kèm tiến độ tiêu dùng
     */
    public function test_get_budgets_with_progress()
    {
        // 1. Tạo trước ngân sách & số tiền đã sử dụng
        $budgetId = (string) Str::uuid7();
        DB::table('budgets')->insert([
            'id' => $budgetId,
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'limit_amount' => 2000000.00,
            'month' => 6,
            'year' => 2026,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('budget_usages')->insert([
            'budget_id' => $budgetId,
            'used_amount' => 500000.00,
            'updated_at' => now()
        ]);

        // 2. Gọi API GET
        $response = $this->getJson('/api/budgets?month=6&year=2026', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(2000000.00, $data[0]['limit_amount']);
        $this->assertEquals(500000.00, $data[0]['used_amount']);
    }

    /**
     * Test 3: Thêm giao dịch chi tiêu mới và cập nhật số dư ngân sách tự động
     */
    public function test_transaction_creation_updates_budget_usage()
    {
        // 1. Tạo ngân sách Ăn uống tháng 6/2026
        $this->postJson('/api/budgets', [
            'category_id' => $this->categoryId,
            'limit_amount' => 1000000.00,
            'month' => 6,
            'year' => 2026
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        // 2. Thêm một giao dịch chi tiêu Ăn uống 300.000đ
        $response = $this->postJson('/api/transactions', [
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 300000.00,
            'title' => 'Ăn trưa văn phòng',
            'transaction_date' => '2026-06-15 12:00:00',
            'currency_code' => 'VND'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);

        // 3. Kiểm tra DB xem budget_usages có tăng lên 300.000đ hay không
        $budget = Budget::where('user_id', $this->userId)
            ->where('category_id', $this->categoryId)
            ->where('month', 6)
            ->where('year', 2026)
            ->first();

        $this->assertNotNull($budget);
        $this->assertDatabaseHas('budget_usages', [
            'budget_id' => $budget->id,
            'used_amount' => 300000.00
        ]);
    }

    /**
     * Test 4: Cảnh báo ngưỡng ngân sách đạt 80% và 100%
     */
    public function test_budget_threshold_alerts_and_notifications()
    {
        Notification::fake();

        // 1. Tạo ngân sách 1.000.000đ
        $response = $this->postJson('/api/budgets', [
            'category_id' => $this->categoryId,
            'limit_amount' => 1000000.00,
            'month' => 6,
            'year' => 2026
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);
        
        $budget = Budget::where('user_id', $this->userId)->first();

        // 2. Tạo chi tiêu 850.000đ (Đạt 85% hạn mức)
        $this->postJson('/api/transactions', [
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 850000.00,
            'title' => 'Mua đồ ăn siêu thị',
            'transaction_date' => '2026-06-15 12:00:00',
            'currency_code' => 'VND'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        // Kiểm tra xem đã sinh ra cảnh báo 80% và gửi email chưa
        $this->assertDatabaseHas('budget_alerts', [
            'budget_id' => $budget->id,
            'threshold_percent' => 80
        ]);
        
        Notification::assertSentTo(
            \App\Models\User::find($this->userId),
            BudgetWarningNotification::class,
            function ($notification, $channels) {
                return in_array('mail', $channels) && strval($notification->toMail(User::find($this->userId))->subject) === '⚡ Cảnh báo ngân sách đạt 80% - Ăn uống';
            }
        );

        // 3. Tạo tiếp chi tiêu 200.000đ (Vượt 100% hạn mức: 1.050.000đ)
        $this->postJson('/api/transactions', [
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 200000.00,
            'title' => 'Ăn tối nhà hàng',
            'transaction_date' => '2026-06-16 19:00:00',
            'currency_code' => 'VND'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $this->assertDatabaseHas('budget_alerts', [
            'budget_id' => $budget->id,
            'threshold_percent' => 100
        ]);

        Notification::assertSentTo(
            \App\Models\User::find($this->userId),
            BudgetWarningNotification::class,
            function ($notification, $channels) {
                return in_array('mail', $channels) && strval($notification->toMail(User::find($this->userId))->subject) === '⚠️ Vượt hạn mức ngân sách - Ăn uống';
            }
        );
    }

    /**
     * Test 5: Sao chép ngân sách từ tháng trước sang tháng mới
     */
    public function test_copy_budgets_from_previous_month()
    {
        // 1. Tạo ngân sách tháng 5/2026
        DB::table('budgets')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'limit_amount' => 1500000.00,
            'month' => 5,
            'year' => 2026,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Gọi API Copy sang tháng 6/2026
        $response = $this->postJson('/api/budgets/copy', [
            'from_month' => 5,
            'from_year' => 2026,
            'to_month' => 6,
            'to_year' => 2026
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('budgets', [
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'limit_amount' => 1500000.00,
            'month' => 6,
            'year' => 2026
        ]);
    }

    /**
     * Test 6: Xóa ngân sách và dọn dẹp các bảng liên quan (Cascade Delete)
     */
    public function test_delete_budget_cleans_related_tables()
    {
        // 1. Tạo ngân sách, usages, alerts
        $budgetId = (string) Str::uuid7();
        DB::table('budgets')->insert([
            'id' => $budgetId,
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'limit_amount' => 1000000.00,
            'month' => 6,
            'year' => 2026,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('budget_usages')->insert([
            'budget_id' => $budgetId,
            'used_amount' => 200000.00,
            'updated_at' => now()
        ]);

        DB::table('budget_alerts')->insert([
            'id' => (string) Str::uuid7(),
            'budget_id' => $budgetId,
            'threshold_percent' => 80,
            'triggered_at' => now()
        ]);

        // 2. Gọi API xóa
        $response = $this->deleteJson("/api/budgets/{$budgetId}", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);

        // 3. Đảm bảo toàn bộ 3 bảng đều sạch bóng dữ liệu của budget đó
        $this->assertDatabaseMissing('budgets', ['id' => $budgetId]);
        $this->assertDatabaseMissing('budget_usages', ['budget_id' => $budgetId]);
        $this->assertDatabaseMissing('budget_alerts', ['budget_id' => $budgetId]);
    }

    /**
     * Test 7: Đổi Preferred Currency tính toán lại giao dịch cũ & ngân sách
     */


    /**
     * Test 8: Không được phép tạo giao dịch thủ công với danh mục thu nhập khi dùng ví ngân hàng/ví điện tử
     */
    public function test_api_creates_manual_transaction_with_income_category_fails()
    {
        // 1. Tạo ví Ngân Hàng
        $bankWalletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $bankWalletId,
            'user_id' => $this->userId,
            'name' => 'Ví Ngân Hàng Test',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $bankWalletId,
            'available_balance' => 5000000.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // 2. Tạo một danh mục thu nhập
        $incomeCategoryId = (string) Str::uuid7();
        DB::table('categories')->insert([
            'id' => $incomeCategoryId,
            'user_id' => null,
            'parent_id' => null,
            'type' => 'income',
            'name' => 'Lương',
            'is_default' => true,
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 3. Tạo người hưởng thụ (bắt buộc đối với ví bank thủ công)
        $payeeId = (string) Str::uuid7();
        DB::table('saved_payees')->insert([
            'id' => $payeeId,
            'user_id' => $this->userId,
            'payee_type' => 'external',
            'identifier' => '123456789',
            'payee_name' => 'Recipient Test',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $response = $this->postJson('/api/transactions', [
            'wallet_id' => $bankWalletId,
            'category_id' => $incomeCategoryId,
            'payee_id' => $payeeId,
            'type' => 'expense',
            'amount' => 100000.00,
            'title' => 'Chuyển tiền lương lỗi',
            'transaction_date' => '2026-06-15 12:00:00',
            'currency_code' => 'VND'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', __('messages.manual_transaction_no_income_category'));
    }

    /**
     * Test 9: Tạo giao dịch thủ công qua ngân hàng/ví điện tử đến người thụ hưởng nội bộ sẽ tự động thực hiện P2P transfer
     */
    public function test_api_creates_manual_transaction_to_internal_payee_performs_p2p_transfer()
    {
        // 1. Tạo ví Ngân Hàng của người gửi
        $senderWalletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $senderWalletId,
            'user_id' => $this->userId,
            'name' => 'Ví Gửi Ngân Hàng',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $senderWalletId,
            'available_balance' => 1000000.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // 2. Tạo ví nhận của người nhận (internal user)
        $recipientId = (string) Str::uuid7();
        DB::table('users')->insert([
            'user_id' => $recipientId,
            'email' => 'recipient_' . uniqid() . '@example.com',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $recipientWalletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $recipientWalletId,
            'user_id' => $recipientId,
            'name' => 'Ví Nhận Ngân Hàng',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        DB::table('wallet_balances')->insert([
            'wallet_id' => $recipientWalletId,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // 3. Tạo payee nội bộ
        $payeeId = (string) Str::uuid7();
        DB::table('saved_payees')->insert([
            'id' => $payeeId,
            'user_id' => $this->userId,
            'payee_type' => 'internal',
            'payee_user_id' => $recipientId,
            'identifier' => 'rec_123',
            'payee_name' => 'Recipient Name',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 4. Tạo giao dịch thủ công chi tiêu 100.000đ từ ví người gửi tới payee nội bộ
        $response = $this->postJson('/api/transactions', [
            'wallet_id' => $senderWalletId,
            'category_id' => $this->categoryId,
            'payee_id' => $payeeId,
            'type' => 'expense',
            'amount' => 100000.00,
            'title' => 'Chuyển tiền thủ công cho A',
            'transaction_date' => '2026-06-15 12:00:00',
            'currency_code' => 'VND'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);

        // 5. Kiểm tra số dư người gửi bị trừ, số dư người nhận được cộng
        $this->assertDatabaseHas('wallet_balances', [
            'wallet_id' => $senderWalletId,
            'available_balance' => 900000.00
        ]);
        $this->assertDatabaseHas('wallet_balances', [
            'wallet_id' => $recipientWalletId,
            'available_balance' => 100000.00
        ]);

        // 6. Kiểm tra giao dịch income được tạo cho người nhận
        $this->assertDatabaseHas('transactions', [
            'user_id' => $recipientId,
            'wallet_id' => $recipientWalletId,
            'type' => 'income',
            'amount' => 100000.00
        ]);
    }
}


