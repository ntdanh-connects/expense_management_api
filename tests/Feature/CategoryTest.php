<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use DatabaseTransactions;

    protected $token;
    protected $userId;
    protected $parentCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo và đăng nhập User
        $auth = $this->authenticateUser();
        $this->token = $auth['token'];
        $this->userId = $auth['user_id'];

        // 2. Tạo danh mục cha hệ thống/mặc định (để tạo danh mục con thuộc cha này)
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
     * Test lấy danh sách các biểu tượng được hỗ trợ
     */
    public function test_get_supported_icons()
    {
        $response = $this->getJson('/api/categories/icons', [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'status',
            'message',
            'data'
        ]);

        $icons = $response->json('data');
        $this->assertIsArray($icons);
        $this->assertContains('building', $icons);
        $this->assertContains('gift_box', $icons);
        $this->assertContains('food', $icons);
    }

    /**
     * Test tạo danh mục tùy chỉnh với icon hợp lệ
     */
    public function test_create_custom_category_valid_icon()
    {
        $response = $this->postJson('/api/categories', [
            'name' => 'Ăn vặt vỉa hè',
            'parent_id' => $this->parentCategoryId,
            'icon' => 'gift_box', // Icon trong danh sách được hỗ trợ
            'color' => '#FF8F9C',
            'sort_order' => 1
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        
        $this->assertDatabaseHas('categories', [
            'user_id' => $this->userId,
            'name' => 'Ăn vặt vỉa hè',
            'icon' => 'gift_box'
        ]);
    }

    /**
     * Test tạo danh mục tùy chỉnh với icon không hợp lệ
     */
    public function test_create_custom_category_invalid_icon()
    {
        $response = $this->postJson('/api/categories', [
            'name' => 'Ăn vặt vỉa hè',
            'parent_id' => $this->parentCategoryId,
            'icon' => 'invalid_icon_name_here', // Icon không có trong danh sách hỗ trợ
            'color' => '#FF8F9C',
            'sort_order' => 1
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('status', 'error');
    }

    /**
     * Test cập nhật danh mục tùy chỉnh với icon hợp lệ
     */
    public function test_update_custom_category_valid_icon()
    {
        // 1. Tạo trước 1 danh mục custom
        $categoryId = (string) Str::uuid7();
        DB::table('categories')->insert([
            'id' => $categoryId,
            'user_id' => $this->userId,
            'parent_id' => $this->parentCategoryId,
            'type' => 'expense',
            'name' => 'Danh mục cũ',
            'icon' => 'food',
            'color' => '#FF8F9C',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Cập nhật icon mới hợp lệ
        $response = $this->postJson("/api/categories/{$categoryId}", [
            'name' => 'Danh mục mới',
            'icon' => 'building', // Hợp lệ
            'color' => '#FF8F9C',
            'sort_order' => 2
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        
        $this->assertDatabaseHas('categories', [
            'id' => $categoryId,
            'name' => 'Danh mục mới',
            'icon' => 'building'
        ]);
    }

    /**
     * Test cập nhật danh mục tùy chỉnh với icon không hợp lệ
     */
    public function test_update_custom_category_invalid_icon()
    {
        // 1. Tạo trước 1 danh mục custom
        $categoryId = (string) Str::uuid7();
        DB::table('categories')->insert([
            'id' => $categoryId,
            'user_id' => $this->userId,
            'parent_id' => $this->parentCategoryId,
            'type' => 'expense',
            'name' => 'Danh mục cũ',
            'icon' => 'food',
            'color' => '#FF8F9C',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Cập nhật icon không hợp lệ
        $response = $this->postJson("/api/categories/{$categoryId}", [
            'icon' => 'invalid_icon_name_here' // Không hợp lệ
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('status', 'error');
    }
}
