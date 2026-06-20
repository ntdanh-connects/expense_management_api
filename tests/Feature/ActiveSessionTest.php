<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActiveSessionTest extends TestCase
{
    use DatabaseTransactions;

    protected $token;
    protected $userId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo và đăng nhập User (session 1)
        $auth = $this->authenticateUser();
        $this->token = $auth['token'];
        $this->userId = $auth['user_id'];
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
            'device_name' => 'PHPUnit Current',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit Agent 1',
            'expired_at' => now()->addDays(30),
            'created_at' => now()
        ]);

        return ['token' => $token, 'user_id' => $userId];
    }

    /**
     * Test 1: Lấy danh sách các phiên hoạt động thành công
     */
    public function test_can_get_active_sessions()
    {
        // Tạo thêm session 2
        $session2Id = (string) Str::uuid7();
        DB::table('user_sessions')->insert([
            'id' => $session2Id,
            'user_id' => $this->userId,
            'refresh_token_hash' => hash('sha256', 'refresh2'),
            'access_token_hash' => hash('sha256', 'token2'),
            'access_token_expired_at' => now()->addHour(),
            'device_type' => 'mobile',
            'device_name' => 'iPhone 15',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'iPhone Agent',
            'expired_at' => now()->addDays(30),
            'created_at' => now()
        ]);

        // Tạo thêm session 3 (đã bị thu hồi)
        $session3Id = (string) Str::uuid7();
        DB::table('user_sessions')->insert([
            'id' => $session3Id,
            'user_id' => $this->userId,
            'refresh_token_hash' => hash('sha256', 'refresh3'),
            'access_token_hash' => hash('sha256', 'token3'),
            'access_token_expired_at' => now()->addHour(),
            'device_type' => 'web',
            'device_name' => 'Chrome Windows',
            'ip_address' => '8.8.8.8',
            'user_agent' => 'Windows Chrome',
            'expired_at' => now()->addDays(30),
            'revoked_at' => now(),
            'created_at' => now()
        ]);

        $response = $this->getJson('/api/user/sessions', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $data = $response->json('data');
        // Chỉ có 2 session active (session 1 và session 2, session 3 bị loại do revoked_at khác null)
        $this->assertCount(2, $data);

        // Kiểm tra xem session hiện tại có is_current = true
        $currentSession = collect($data)->firstWhere('device_name', 'PHPUnit Current');
        $this->assertNotNull($currentSession);
        $this->assertTrue($currentSession['is_current']);

        // Kiểm tra session khác có is_current = false
        $otherSession = collect($data)->firstWhere('device_name', 'iPhone 15');
        $this->assertNotNull($otherSession);
        $this->assertFalse($otherSession['is_current']);
    }

    /**
     * Test 2: Hủy (đăng xuất) một phiên cụ thể thành công
     */
    public function test_can_revoke_specific_session()
    {
        // Tạo thêm session 2
        $session2Id = (string) Str::uuid7();
        DB::table('user_sessions')->insert([
            'id' => $session2Id,
            'user_id' => $this->userId,
            'refresh_token_hash' => hash('sha256', 'refresh2'),
            'access_token_hash' => hash('sha256', 'token2'),
            'access_token_expired_at' => now()->addHour(),
            'device_type' => 'mobile',
            'device_name' => 'iPhone 15',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'iPhone Agent',
            'expired_at' => now()->addDays(30),
            'created_at' => now()
        ]);

        $response = $this->deleteJson("/api/user/sessions/{$session2Id}", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Đảm bảo session 2 đã bị set revoked_at
        $this->assertDatabaseMissing('user_sessions', [
            'id' => $session2Id,
            'revoked_at' => null
        ]);
    }

    /**
     * Test 3: Đăng nhập từ thiết bị mới sẽ thu hồi các phiên đăng nhập cũ
     */
    public function test_login_from_new_device_revokes_old_sessions()
    {
        // 1. Tạo một tài khoản user hoàn chỉnh có mật khẩu
        $email = 'single_session_' . uniqid() . '@example.com';
        $password = 'SecretPassword123';
        $userId = (string) Str::uuid7();

        DB::table('users')->insert([
            'user_id' => $userId,
            'email' => $email,
            'status' => 'active',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('user_credentials')->insert([
            'user_id' => $userId,
            'password_hash' => \Illuminate\Support\Facades\Hash::make($password)
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => $userId,
            'full_name' => 'Single Session User',
            'created_at' => now()
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

        // 2. Thiết bị A đăng nhập lần đầu
        $loginAResponse = $this->postJson('/api/login', [
            'email' => $email,
            'password' => $password
        ], [
            'User-Agent' => 'Device A Browser'
        ]);

        $loginAResponse->assertStatus(200);
        $tokenA = $loginAResponse->json('access_token');

        // Gửi request bằng Token A -> Thành công (200)
        $profileResponse = $this->getJson('/api/user/profile', [
            'Authorization' => 'Bearer ' . $tokenA
        ]);
        $profileResponse->assertStatus(200);

        // 3. Thiết bị B đăng nhập lần thứ hai
        $loginBResponse = $this->postJson('/api/login', [
            'email' => $email,
            'password' => $password
        ], [
            'User-Agent' => 'Device B Browser'
        ]);

        $loginBResponse->assertStatus(200);
        $tokenB = $loginBResponse->json('access_token');

        // Gửi request bằng Token A -> Phải thất bại (401) vì đã bị thu hồi
        $profileResponseA = $this->getJson('/api/user/profile', [
            'Authorization' => 'Bearer ' . $tokenA
        ]);
        $profileResponseA->assertStatus(401);

        // Gửi request bằng Token B -> Vẫn thành công (200)
        $profileResponseB = $this->getJson('/api/user/profile', [
            'Authorization' => 'Bearer ' . $tokenB
        ]);
        $profileResponseB->assertStatus(200);
    }
}
