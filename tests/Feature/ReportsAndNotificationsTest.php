<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Jobs\ExportTransactionsJob;

class ReportsAndNotificationsTest extends TestCase
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

        // 2. Tạo danh mục mặc định
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

        // 3. Tạo ví mặc định
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
            'available_balance' => 10000000.00,
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
     * Helper: tạo giao dịch và cập nhật thống kê tương ứng
     */
    protected function createTransactionWithStats(string $type, float $amount, string $dateStr, ?string $categoryId = null): string
    {
        $txId = (string) Str::uuid7();
        $categoryId = $categoryId ?? $this->categoryId;
        $date = \Carbon\Carbon::parse($dateStr);

        DB::table('transactions')->insert([
            'id' => $txId,
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $categoryId,
            'type' => $type,
            'amount' => $amount,
            'amount_in_user_currency' => $amount,
            'currency_code' => 'VND',
            'title' => 'Test transaction',
            'status' => 'completed',
            'transaction_date' => $dateStr,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Cập nhật daily_statistics
        $incomeDiff = $type === 'income' ? $amount : 0;
        $expenseDiff = $type === 'expense' ? $amount : 0;

        DB::statement("
            INSERT INTO daily_statistics (user_id, date, income, expense, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON CONFLICT (user_id, date) DO UPDATE
            SET income = COALESCE(daily_statistics.income, 0) + EXCLUDED.income,
                expense = COALESCE(daily_statistics.expense, 0) + EXCLUDED.expense,
                updated_at = NOW()
        ", [$this->userId, $date->toDateString(), $incomeDiff, $expenseDiff]);

        // Cập nhật monthly_statistics
        DB::statement("
            INSERT INTO monthly_statistics (user_id, month, year, income, expense, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON CONFLICT (user_id, month, year) DO UPDATE
            SET income = COALESCE(monthly_statistics.income, 0) + EXCLUDED.income,
                expense = COALESCE(monthly_statistics.expense, 0) + EXCLUDED.expense,
                updated_at = NOW()
        ", [$this->userId, $date->month, $date->year, $incomeDiff, $expenseDiff]);

        // Cập nhật category_statistics
        if ($categoryId) {
            DB::statement("
                INSERT INTO category_statistics (user_id, category_id, month, year, total_amount, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON CONFLICT (user_id, category_id, month, year) DO UPDATE
                SET total_amount = COALESCE(category_statistics.total_amount, 0) + EXCLUDED.total_amount,
                    updated_at = NOW()
            ", [$this->userId, $categoryId, $date->month, $date->year, $amount]);
        }

        return $txId;
    }

    // =====================================================================
    // MODULE 6: REPORTS & STATISTICS
    // =====================================================================

    /**
     * Test 1: Lấy báo cáo tổng hợp thu chi (summary)
     */
    public function test_reports_summary_returns_income_expense_net()
    {
        // Tạo giao dịch mẫu
        $this->createTransactionWithStats('expense', 500000, '2026-06-05');
        $this->createTransactionWithStats('expense', 300000, '2026-06-06');
        $this->createTransactionWithStats('income', 1000000, '2026-06-05');

        $response = $this->getJson('/api/reports/summary?start_date=2026-06-01&end_date=2026-06-30', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'status',
            'data' => ['income', 'expense', 'net']
        ]);

        $data = $response->json('data');
        $this->assertEquals(1000000, $data['income']);
        $this->assertEquals(800000, $data['expense']);
        $this->assertEquals(200000, $data['net']);
    }

    /**
     * Test 2: Summary trả 422 nếu thiếu start_date hoặc end_date
     */
    public function test_reports_summary_validation_error()
    {
        $response = $this->getJson('/api/reports/summary', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }

    /**
     * Test 3: Báo cáo theo danh mục
     */
    public function test_reports_category_returns_category_breakdown()
    {
        // Tạo giao dịch chi tiêu cho danh mục
        $this->createTransactionWithStats('expense', 500000, '2026-06-10');
        $this->createTransactionWithStats('expense', 300000, '2026-06-12');

        $response = $this->getJson('/api/reports/categories?month=6&year=2026&type=expense', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'status',
            'data' => ['total_amount', 'categories']
        ]);

        $data = $response->json('data');
        $this->assertEquals(800000, $data['total_amount']);
        $this->assertNotEmpty($data['categories']);
    }

    /**
     * Test 4: Xu hướng thu chi theo ngày
     */
    public function test_reports_trends_daily()
    {
        $this->createTransactionWithStats('expense', 200000, '2026-06-05');
        $this->createTransactionWithStats('income', 500000, '2026-06-06');

        $response = $this->getJson('/api/reports/trends?start_date=2026-06-01&end_date=2026-06-30&group_by=day', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        // Kiểm tra cấu trúc một phần tử
        $firstItem = $data[0];
        $this->assertArrayHasKey('label', $firstItem);
        $this->assertArrayHasKey('date', $firstItem);
        $this->assertArrayHasKey('income', $firstItem);
        $this->assertArrayHasKey('expense', $firstItem);
    }

    /**
     * Test 5: Xu hướng thu chi theo tháng
     */
    public function test_reports_trends_monthly()
    {
        $this->createTransactionWithStats('expense', 100000, '2026-06-05');

        $response = $this->getJson('/api/reports/trends?start_date=2026-01-01&end_date=2026-12-31&group_by=month', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $firstItem = $data[0];
        $this->assertArrayHasKey('label', $firstItem);
        $this->assertArrayHasKey('month', $firstItem);
        $this->assertArrayHasKey('year', $firstItem);
    }

    // =====================================================================
    // MODULE 7: EXPORT / IMPORT (QUEUE-BASED)
    // =====================================================================

    /**
     * Test 6: Gửi yêu cầu xuất file CSV (dispatch job vào Queue)
     */
    public function test_export_transactions_dispatches_job()
    {
        Queue::fake();

        $response = $this->postJson('/api/transactions/export', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure(['status', 'message', 'export_id']);

        $exportId = $response->json('export_id');
        $this->assertNotEmpty($exportId);

        // Kiểm tra bản ghi report_exports đã được tạo
        $this->assertDatabaseHas('report_exports', [
            'id' => $exportId,
            'user_id' => $this->userId,
            'status' => 'pending',
        ]);

        // Kiểm tra job đã được dispatch
        Queue::assertPushed(ExportTransactionsJob::class);
    }

    /**
     * Test 7: Lấy danh sách lịch sử xuất file
     */
    public function test_list_exports()
    {
        // Tạo dữ liệu mẫu
        $exportId = (string) Str::uuid7();
        DB::table('report_exports')->insert([
            'id' => $exportId,
            'user_id' => $this->userId,
            'status' => 'completed',
            'file_url' => 'https://example.com/file.csv',
            'filters' => json_encode(['start_date' => '2026-06-01']),
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/transactions/exports', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'status',
            'data',
            'pagination' => ['total', 'per_page', 'current_page', 'last_page']
        ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals($exportId, $data[0]['id']);
    }

    // =====================================================================
    // MODULE 8: NOTIFICATIONS
    // =====================================================================

    /**
     * Test 11: Lấy danh sách thông báo (phân trang)
     */
    public function test_notifications_index()
    {
        // Tạo thông báo mẫu
        for ($i = 0; $i < 3; $i++) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $this->userId,
                'type' => 'test',
                'title' => "Thông báo $i",
                'content' => "Nội dung thông báo $i",
                'metadata' => json_encode(['key' => 'value']),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        $response = $this->getJson('/api/notifications', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'status',
            'data',
            'pagination' => ['total', 'per_page', 'current_page', 'last_page']
        ]);

        $this->assertCount(3, $response->json('data'));
    }

    /**
     * Test 12: Đánh dấu một thông báo đã đọc
     */
    public function test_notification_mark_read()
    {
        $notifId = (string) Str::uuid7();
        DB::table('notifications')->insert([
            'id' => $notifId,
            'user_id' => $this->userId,
            'type' => 'test',
            'title' => 'Test thông báo',
            'content' => 'Nội dung',
            'metadata' => json_encode([]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $response = $this->postJson("/api/notifications/{$notifId}/read", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Kiểm tra read_at đã được cập nhật
        $notification = DB::table('notifications')->where('id', $notifId)->first();
        $this->assertNotNull($notification->read_at);
    }

    /**
     * Test 13: Đánh dấu đọc tất cả thông báo
     */
    public function test_notification_mark_read_all()
    {
        for ($i = 0; $i < 5; $i++) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $this->userId,
                'type' => 'test',
                'title' => "Thông báo $i",
                'content' => "Nội dung $i",
                'metadata' => json_encode([]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        $response = $this->postJson('/api/notifications/read-all', [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Kiểm tra tất cả đã được đọc
        $unreadCount = DB::table('notifications')
            ->where('user_id', $this->userId)
            ->whereNull('read_at')
            ->count();

        $this->assertEquals(0, $unreadCount);
    }

    /**
     * Test 14: Xóa thông báo
     */
    public function test_notification_delete()
    {
        $notifId = (string) Str::uuid7();
        DB::table('notifications')->insert([
            'id' => $notifId,
            'user_id' => $this->userId,
            'type' => 'test',
            'title' => 'Sắp xóa',
            'content' => 'Nội dung',
            'metadata' => json_encode([]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $response = $this->deleteJson("/api/notifications/{$notifId}", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Kiểm tra đã bị xóa
        $this->assertDatabaseMissing('notifications', ['id' => $notifId]);
    }

    /**
     * Test 15: Xóa thông báo không tồn tại trả 404
     */
    public function test_notification_delete_not_found()
    {
        $fakeId = (string) Str::uuid7();
        $response = $this->deleteJson("/api/notifications/{$fakeId}", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(404);
    }

    /**
     * Test 16: Đánh dấu đọc thông báo không tồn tại trả 404
     */
    public function test_notification_mark_read_not_found()
    {
        $fakeId = (string) Str::uuid7();
        $response = $this->postJson("/api/notifications/{$fakeId}/read", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(404);
    }

    /**
     * Test 17: Lấy cấu hình cài đặt thông báo (mặc định)
     */
    public function test_get_notification_preferences_default()
    {
        $response = $this->getJson('/api/notifications/preferences', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.email_enabled', true);
        $response->assertJsonPath('data.push_enabled', true);
        $response->assertJsonPath('data.weekly_summary_enabled', true);
        $response->assertJsonPath('data.daily_reminder_enabled', true);
    }

    /**
     * Test 18: Cập nhật cấu hình cài đặt thông báo
     */
    public function test_update_notification_preferences()
    {
        $response = $this->postJson('/api/notifications/preferences', [
            'email_enabled' => false,
            'push_enabled' => true,
            'weekly_summary_enabled' => false,
            'daily_reminder_enabled' => false,
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.email_enabled', false);
        $response->assertJsonPath('data.push_enabled', true);
        $response->assertJsonPath('data.weekly_summary_enabled', false);
        $response->assertJsonPath('data.daily_reminder_enabled', false);

        // Kiểm tra database
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->userId,
            'email_enabled' => false,
            'push_enabled' => true,
            'weekly_summary_enabled' => false,
            'daily_reminder_enabled' => false,
        ]);
    }

    /**
     * Test 19: Command notification:daily-reminder hoạt động chính xác
     */
    public function test_daily_reminder_artisan_command()
    {
        // Xóa tất cả các giao dịch của ngày hôm nay
        DB::table('transactions')->where('user_id', $this->userId)->delete();

        // Chạy lệnh console
        $this->artisan('notification:daily-reminder')
            ->assertExitCode(0);

        // Kiểm tra có thông báo nhắc nhở được tạo trong db
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->userId,
            'title' => 'Nhắc nhở ghi chép chi tiêu',
        ]);
    }

    /**
     * Test 20: Command notification:weekly-summary hoạt động chính xác
     */
    public function test_weekly_summary_artisan_command()
    {
        // Tạo giao dịch để có dữ liệu tóm tắt tuần qua
        $this->createTransactionWithStats('expense', 150000, now()->format('Y-m-d'));

        // Chạy lệnh console
        $this->artisan('notification:weekly-summary')
            ->assertExitCode(0);

        // Kiểm tra có thông báo tóm tắt tuần được tạo trong db
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->userId,
            'title' => 'Báo cáo tuần qua',
        ]);
    }

    /**
     * Test 21: Trigger cron API thành công với secret đúng
     */
    public function test_cron_trigger_api_success()
    {
        // Sử dụng secret mặc định trong code hoặc thiết lập tạm thời
        $secret = env('CRON_SECRET', 'expense_cron_secure_key_2026');

        $response = $this->getJson("/api/cron-trigger?secret={$secret}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    }

    /**
     * Test 22: Trigger cron API thất bại với secret sai
     */
    public function test_cron_trigger_api_unauthorized()
    {
        $response = $this->getJson("/api/cron-trigger?secret=wrong_secret");

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'error');
    }
}
