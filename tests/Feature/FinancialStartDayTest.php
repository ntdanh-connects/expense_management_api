<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Carbon;

class FinancialStartDayTest extends TestCase
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
     * Test 1: Đổi financial_start_day thành công qua profile API
     */
    public function test_can_update_financial_start_day_via_api()
    {
        $response = $this->postJson('/api/user/profile', [
            'financial_start_day' => 5
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->userId,
            'financial_start_day' => 5
        ]);
    }

    /**
     * Test 2: Thay đổi financial_start_day tự động tính toán lại ngân sách theo chu kỳ mới
     */
    public function test_changing_financial_start_day_recalculates_budget_usages()
    {
        // 1. Tạo ngân sách tháng 6/2026
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
            'used_amount' => 0,
            'updated_at' => now()
        ]);

        // 2. Tạo 2 giao dịch:
        // Tx A: Ngày 2026-06-02 (nằm ngoài chu kỳ tháng 6 tài chính nếu bắt đầu từ ngày 5, nhưng nằm trong tháng 6 dương lịch)
        DB::table('transactions')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'status' => 'completed',
            'amount' => 150000.00,
            'amount_in_user_currency' => 150000.00,
            'currency_code' => 'VND',
            'transaction_date' => '2026-06-02 12:00:00',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Tx B: Ngày 2026-06-10 (nằm trong chu kỳ tháng 6 tài chính nếu bắt đầu từ ngày 5)
        DB::table('transactions')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'status' => 'completed',
            'amount' => 250000.00,
            'amount_in_user_currency' => 250000.00,
            'currency_code' => 'VND',
            'transaction_date' => '2026-06-10 12:00:00',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Ban đầu financial_start_day = 1 (Tháng 6 tài chính = 1/6 -> 30/6): Cả 2 giao dịch đều tính -> used_amount = 400.000đ
        $budgetService = app(\App\Services\BudgetService::class);
        $budgetService->recalculateSingleBudget(Budget::find($budgetId));
        $this->assertDatabaseHas('budget_usages', [
            'budget_id' => $budgetId,
            'used_amount' => 400000.00
        ]);

        // Đổi sang ngày tài chính = 5 (Tháng 6 tài chính = 5/6 -> 4/7): Tx A (2/6) sẽ thuộc chu kỳ tháng 5 tài chính (5/5 -> 4/6).
        // Chỉ có Tx B (10/6) tính vào tháng 6 tài chính -> used_amount = 250.000đ
        $response = $this->postJson('/api/user/profile', [
            'financial_start_day' => 5
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('budget_usages', [
            'budget_id' => $budgetId,
            'used_amount' => 250000.00
        ]);
    }

    /**
     * Test 3: Dashboard summary trả về khoảng thời gian và tổng hợp chính xác theo ngày bắt đầu tài chính
     */
    public function test_dashboard_summary_respects_financial_start_day()
    {
        // 1. Cập nhật ngày tài chính = 10
        DB::table('user_preferences')
            ->where('user_id', $this->userId)
            ->update(['financial_start_day' => 10]);

        // Giả sử hôm nay là ngày 15/06/2026 -> Chu kỳ hiện tại: 10/06/2026 -> 09/07/2026.
        // Tạo giao dịch ngày 12/06/2026 (Trong chu kỳ)
        DB::table('transactions')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'status' => 'completed',
            'amount' => 300000.00,
            'amount_in_user_currency' => 300000.00,
            'currency_code' => 'VND',
            'transaction_date' => '2026-06-12 12:00:00',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Tạo giao dịch ngày 05/06/2026 (Ngoài chu kỳ, thuộc chu kỳ 10/05 -> 09/06)
        DB::table('transactions')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'status' => 'completed',
            'amount' => 150000.00,
            'amount_in_user_currency' => 150000.00,
            'currency_code' => 'VND',
            'transaction_date' => '2026-06-05 12:00:00',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mock thời gian hiện tại về 15/06/2026
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 0, 'Asia/Ho_Chi_Minh'));

        $response = $this->getJson('/api/dashboard/summary', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $summary = $response->json('data.summary');

        // Tổng chi tiêu chỉ gồm giao dịch ngày 12/06 (300.000đ)
        $this->assertEquals(300000.00, $summary['expense']);

        Carbon::setTestNow(); // Reset mock time
    }
}
