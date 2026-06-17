<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Budget;
use App\Models\RecurringRule;
use App\Services\FcmService;
use App\Notifications\BudgetWarningNotification;
use App\Notifications\RecurringTransactionExecutedNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class FcmPushNotificationTest extends TestCase
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
            'name' => 'Ví Tiền Một',
            'type' => 'cash',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id' => $this->walletId,
            'available_balance' => 2000000.00,
            'version' => 1,
            'updated_at' => now()
        ]);

        // 4. Đảm bảo cấu hình Preferences & Notification Preferences đã tồn tại cho user
        DB::table('notification_preferences')->updateOrInsert(
            ['user_id' => $this->userId],
            [
                'email_enabled' => true,
                'push_enabled' => true,
                'weekly_summary_enabled' => true,
                'daily_reminder_enabled' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
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

        return [
            'token' => $token,
            'user_id' => $userId
        ];
    }

    /**
     * Test đăng ký token nhận thông báo đẩy.
     */
    public function test_user_can_register_device_token()
    {
        $response = $this->postJson('/api/user/device-token', [
            'device_token' => 'fcm-test-token-123456',
            'device_type' => 'android'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Đăng ký thiết bị nhận thông báo thành công.');

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->userId,
            'device_token' => 'fcm-test-token-123456',
            'device_type' => 'android'
        ]);
    }

    /**
     * Test hủy đăng ký token khi logout/không dùng.
     */
    public function test_user_can_unregister_device_token()
    {
        // Thêm trước token vào DB
        DB::table('user_device_tokens')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'device_token' => 'fcm-delete-token',
            'device_type' => 'ios',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $response = $this->deleteJson('/api/user/device-token', [
            'device_token' => 'fcm-delete-token'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Hủy đăng ký thiết bị thành công.');

        $this->assertDatabaseMissing('user_device_tokens', [
            'device_token' => 'fcm-delete-token'
        ]);
    }

    /**
     * Test gửi thông báo qua FCM khi ngân sách vượt quá ngưỡng 80% (tích hợp).
     */
    public function test_budget_warning_triggers_fcm_notification()
    {
        Mail::fake();

        // Đăng ký token cho user
        DB::table('user_device_tokens')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'device_token' => 'budget-fcm-token',
            'device_type' => 'android',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mock FcmService
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('sendNotification')
                ->once()
                ->with(
                    ['budget-fcm-token'],
                    \Mockery::pattern('/Cảnh báo ngân sách/'),
                    \Mockery::pattern('/đã đạt 80%/'),
                    \Mockery::type('array')
                )
                ->andReturn(true);
        });

        // Thiết lập ngân sách 1.000.000đ cho tháng hiện tại
        $now = now();
        $budget = Budget::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'limit_amount' => 1000000.00,
            'month' => $now->month,
            'year' => $now->year,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Tạo chi tiêu 850.000đ (đạt 85% hạn mức)
        $this->postJson('/api/transactions', [
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 850000.00,
            'title' => 'Mua sắm',
            'transaction_date' => $now->format('Y-m-d H:i:s'),
            'currency_code' => 'VND'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);
    }

    /**
     * Test trực tiếp hàm notify gửi thông báo định kỳ.
     */
    public function test_recurring_transaction_fcm_notification()
    {
        Mail::fake();

        // Đăng ký token
        DB::table('user_device_tokens')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'device_token' => 'recurring-fcm-token',
            'device_type' => 'web',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mock FcmService
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('sendNotification')
                ->once()
                ->with(
                    ['recurring-fcm-token'],
                    '⚠️ Lỗi giao dịch định kỳ',
                    \Mockery::pattern('/thực thi thất bại/'),
                    \Mockery::type('array')
                )
                ->andReturn(true);
        });

        $rule = RecurringRule::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'category_id' => $this->categoryId,
            'type' => 'expense',
            'amount' => 150000.00,
            'title' => 'Đóng tiền nét định kỳ',
            'frequency' => 'monthly',
            'interval' => 1,
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $user = User::find($this->userId);
        $notification = new RecurringTransactionExecutedNotification($rule, null, 'failed', 'Ví không đủ số dư để thanh toán');
        
        $user->notify($notification);
    }

    /**
     * Test channels selected when both email and push notifications are enabled.
     */
    public function test_p2p_transfer_received_notification_channels_both_enabled()
    {
        \Illuminate\Support\Facades\Notification::fake();

        $user = User::find($this->userId);

        DB::table('notification_preferences')->where('user_id', $this->userId)->update([
            'email_enabled' => true,
            'push_enabled' => true,
        ]);

        $notification = new \App\Notifications\P2pTransferReceivedNotification('Sender A', 100000.00, 'VND', 'Chuyển tiền');
        $user->notify($notification);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $user,
            \App\Notifications\P2pTransferReceivedNotification::class,
            function ($notif, $channels) {
                return in_array('mail', $channels) 
                    && in_array(\App\Channels\FcmChannel::class, $channels)
                    && in_array(\App\Channels\CustomDbChannel::class, $channels);
            }
        );
    }

    /**
     * Test channels selected when only push notification is enabled.
     */
    public function test_p2p_transfer_received_notification_channels_only_push()
    {
        \Illuminate\Support\Facades\Notification::fake();

        $user = User::find($this->userId);

        DB::table('notification_preferences')->where('user_id', $this->userId)->update([
            'email_enabled' => false,
            'push_enabled' => true,
        ]);

        $notification = new \App\Notifications\P2pTransferReceivedNotification('Sender A', 100000.00, 'VND', 'Chuyển tiền');
        $user->notify($notification);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $user,
            \App\Notifications\P2pTransferReceivedNotification::class,
            function ($notif, $channels) {
                return !in_array('mail', $channels) 
                    && in_array(\App\Channels\FcmChannel::class, $channels)
                    && in_array(\App\Channels\CustomDbChannel::class, $channels);
            }
        );
    }

    /**
     * Test channels selected when only email notification is enabled.
     */
    public function test_p2p_transfer_received_notification_channels_only_email()
    {
        \Illuminate\Support\Facades\Notification::fake();

        $user = User::find($this->userId);

        DB::table('notification_preferences')->where('user_id', $this->userId)->update([
            'email_enabled' => true,
            'push_enabled' => false,
        ]);

        $notification = new \App\Notifications\P2pTransferReceivedNotification('Sender A', 100000.00, 'VND', 'Chuyển tiền');
        $user->notify($notification);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $user,
            \App\Notifications\P2pTransferReceivedNotification::class,
            function ($notif, $channels) {
                return in_array('mail', $channels) 
                    && !in_array(\App\Channels\FcmChannel::class, $channels)
                    && in_array(\App\Channels\CustomDbChannel::class, $channels);
            }
        );
    }
}

