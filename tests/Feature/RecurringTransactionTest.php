<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use App\Models\RecurringRule;
use App\Models\RecurringExecution;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RecurringTransactionExecutedNotification;

class RecurringTransactionTest extends TestCase
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
     * Test 1: Tạo lịch định kỳ qua API và tự động gán start_date
     */
    public function test_api_creates_recurring_rule_with_start_date()
    {
        $response = $this->postJson('/api/recurring-rules', [
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 150000.00,
            'title' => 'Đăng ký Netflix',
            'frequency' => 'monthly',
            'interval_value' => 1,
            'next_run_at' => '2026-06-15 12:00:00'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('recurring_rules', [
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'amount' => 150000.00,
            'frequency' => 'monthly',
            'start_date' => '2026-06-15 12:00:00',
            'next_run_at' => '2026-06-15 12:00:00'
        ]);
    }

    /**
     * Test 2: Chu kỳ tháng co-giãn động khi các tháng thiếu ngày
     */
    public function test_monthly_rule_adjusts_dynamically_for_different_month_lengths()
    {
        Notification::fake();
        Carbon::setTestNow('2026-01-31 10:00:00');

        // Tạo rule bắt đầu ngày 31/01
        $rule = RecurringRule::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'income',
            'amount' => 100000.00,
            'title' => 'Lương tháng 31',
            'frequency' => 'monthly',
            'interval_value' => 1,
            'start_date' => Carbon::parse('2026-01-31 10:00:00'),
            'next_run_at' => Carbon::parse('2026-01-31 10:00:00'),
            'is_active' => true
        ]);

        // 1. Chạy lần 1 (ngày 31/01)
        $this->artisan('recurring:process')->assertExitCode(0);

        $rule->refresh();
        // Ngày chạy tiếp theo phải co lại thành 2026-02-28
        $this->assertEquals('2026-02-28 10:00:00', $rule->next_run_at->toDateTimeString());

        // 2. Chạy lần 2 (ngày 28/02)
        Carbon::setTestNow('2026-02-28 10:05:00');
        $this->artisan('recurring:process')->assertExitCode(0);

        $rule->refresh();
        // Ngày chạy tiếp theo phải giãn ra quay lại ngày 31/03
        $this->assertEquals('2026-03-31 10:00:00', $rule->next_run_at->toDateTimeString());

        // 3. Chạy lần 3 (ngày 31/03)
        Carbon::setTestNow('2026-03-31 10:05:00');
        $this->artisan('recurring:process')->assertExitCode(0);

        $rule->refresh();
        // Ngày chạy tiếp theo phải co về ngày 30/04 (vì tháng 4 có 30 ngày)
        $this->assertEquals('2026-04-30 10:00:00', $rule->next_run_at->toDateTimeString());

        Carbon::setTestNow(); // Reset time
    }

    /**
     * Test 3: Xử lý downtime bằng cách chạy bù giao dịch lỡ và ghi đúng ngày chu kỳ thực tế
     */
    public function test_downtime_catch_up_creates_multiple_transactions_with_correct_dates()
    {
        Notification::fake();
        Carbon::setTestNow('2026-06-08 10:00:00'); // Hôm nay

        // Tạo một rule chu kỳ hàng ngày, đáng lẽ phải chạy từ 3 ngày trước (05/06)
        // Hệ thống bị downtime nên next_run_at vẫn là 2026-06-05
        $rule = RecurringRule::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'income',
            'amount' => 50000.00,
            'title' => 'Tự động tích luỹ hàng ngày',
            'frequency' => 'daily',
            'interval_value' => 1,
            'start_date' => Carbon::parse('2026-06-05 10:00:00'),
            'next_run_at' => Carbon::parse('2026-06-05 10:00:00'),
            'is_active' => true
        ]);

        // Chạy command xử lý định kỳ
        $this->artisan('recurring:process')->assertExitCode(0);

        // Hệ thống lặp qua: 05/06, 06/06, 07/06 và 08/06 (do 08/06 10:00 lte now 10:00).
        // Tổng cộng tạo ra 4 giao dịch.
        $txs = Transaction::where('source_id', $rule->id)->orderBy('transaction_date', 'asc')->get();
        $this->assertCount(4, $txs);

        // Kiểm tra xem transaction_date có được ghi nhận chính xác theo thời điểm đáng lẽ xảy ra hay không
        $this->assertEquals('2026-06-05 10:00:00', $txs[0]->transaction_date->toDateTimeString());
        $this->assertEquals('2026-06-06 10:00:00', $txs[1]->transaction_date->toDateTimeString());
        $this->assertEquals('2026-06-07 10:00:00', $txs[2]->transaction_date->toDateTimeString());
        $this->assertEquals('2026-06-08 10:00:00', $txs[3]->transaction_date->toDateTimeString());

        // Kiểm tra next_run_at của rule đã được dịch lên ngày mai
        $rule->refresh();
        $this->assertEquals('2026-06-09 10:00:00', $rule->next_run_at->toDateTimeString());

        // Kiểm tra 4 thông báo đã được gửi đi
        Notification::assertSentTo(
            User::find($this->userId),
            RecurringTransactionExecutedNotification::class,
            4
        );

        Carbon::setTestNow();
    }

    /**
     * Test 4: Giao dịch thất bại do ví thiếu tiền, ghi nhận log và bắn thông báo cảnh báo
     */
    public function test_insufficient_wallet_balance_logs_failures_and_advances_date_and_sends_alert()
    {
        Notification::fake();
        Carbon::setTestNow('2026-06-08 10:00:00');

        // Set số dư ví = 0
        DB::table('wallet_balances')->where('wallet_id', $this->walletId)->update([
            'available_balance' => 0.00
        ]);

        // Tạo rule chi tiêu (expense) 100.000đ hàng ngày bắt đầu từ 3 ngày trước (05/06)
        $rule = RecurringRule::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 100000.00,
            'title' => 'Trừ tiền điện hàng ngày',
            'frequency' => 'daily',
            'interval_value' => 1,
            'start_date' => Carbon::parse('2026-06-05 10:00:00'),
            'next_run_at' => Carbon::parse('2026-06-05 10:00:00'),
            'is_active' => true
        ]);

        $this->artisan('recurring:process')->assertExitCode(0);

        // Do thiếu tiền:
        // - Số giao dịch tạo ra phải là 0
        $this->assertCount(0, Transaction::where('source_id', $rule->id)->get());

        // - Số lượt ghi nhận lịch sử thất bại phải là 4 (05, 06, 07, 08)
        $this->assertCount(4, RecurringExecution::where('recurring_rule_id', $rule->id)->where('status', 'failed')->get());

        // - next_run_at của rule vẫn phải được dịch lên ngày mai
        $rule->refresh();
        $this->assertEquals('2026-06-09 10:00:00', $rule->next_run_at->toDateTimeString());

        // - Gửi 4 thông báo lỗi
        Notification::assertSentTo(
            User::find($this->userId),
            RecurringTransactionExecutedNotification::class,
            function ($notification) {
                return $notification->toArray(User::find($this->userId))['status'] === 'failed';
            }
        );

        Carbon::setTestNow();
    }

    /**
     * Test 5: Nếu người dùng đã tự nhập giao dịch thủ công hôm nay, scheduler quét định kỳ sẽ BỎ QUA không ghi nhận trùng lặp
     */
    public function test_recurring_process_skips_if_manually_logged_today()
    {
        Notification::fake();
        Carbon::setTestNow('2026-06-08 10:00:00');

        // 1. Người dùng đã tự tạo thủ công một giao dịch y hệt hôm nay
        DB::table('transactions')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 80000.00,
            'amount_in_user_currency' => 80000.00,
            'currency_code' => 'VND',
            'title' => 'Tiền điện hàng ngày',
            'status' => 'completed',
            'transaction_date' => '2026-06-08 08:30:00', // Sớm hơn trong ngày
            'source_type' => 'manual',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Tạo một rule cho ngày 08/06
        $rule = RecurringRule::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 80000.00,
            'title' => 'Tiền điện hàng ngày',
            'frequency' => 'daily',
            'interval_value' => 1,
            'start_date' => Carbon::parse('2026-06-08 10:00:00'),
            'next_run_at' => Carbon::parse('2026-06-08 10:00:00'),
            'is_active' => true
        ]);

        // 3. Chạy command định kỳ
        $this->artisan('recurring:process')->assertExitCode(0);

        // Kế hoạch quét định kỳ phải bị BỎ QUA (skipped) -> không tạo thêm transaction mới
        $this->assertCount(1, Transaction::where('title', 'Tiền điện hàng ngày')->get()); // Chỉ có 1 giao dịch thủ công, không có giao dịch thứ 2 từ scheduler
        
        $execution = RecurringExecution::where('recurring_rule_id', $rule->id)->first();
        $this->assertNotNull($execution);
        $this->assertEquals('skipped', $execution->status);

        // Ngày chạy tiếp theo vẫn phải được tăng lên ngày mai
        $rule->refresh();
        $this->assertEquals('2026-06-09 10:00:00', $rule->next_run_at->toDateTimeString());

        Carbon::setTestNow();
    }

    /**
     * Test 6: Nếu scheduler đã chạy tự động hôm nay, người dùng KHÔNG ĐƯỢC PHÉP tự ghi nhận thủ công nữa (chặn trùng lặp)
     */
    public function test_manual_logging_blocked_if_recurring_already_logged_today()
    {
        Notification::fake();
        Carbon::setTestNow('2026-06-08 10:00:00');

        // 1. Tạo giao dịch đã được tự động chạy từ scheduler ngày hôm nay
        DB::table('transactions')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 120000.00,
            'amount_in_user_currency' => 120000.00,
            'currency_code' => 'VND',
            'title' => 'Tiền mạng cáp quang',
            'status' => 'completed',
            'transaction_date' => '2026-06-08 10:00:00',
            'source_type' => 'recurring',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Thử tạo thủ công giao dịch y hệt thông qua API
        $response = $this->postJson('/api/transactions', [
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 120000.00,
            'title' => 'Tiền mạng cáp quang',
            'transaction_date' => '2026-06-08 11:30:00', // Cùng ngày
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        // Phải bị lỗi chặn trùng lặp (trả về 400 từ Controller khi bắt được exception)
        $response->assertStatus(400);
        $response->assertJsonPath('message', __('messages.transaction_already_logged_automatically'));

        Carbon::setTestNow();
    }
}
