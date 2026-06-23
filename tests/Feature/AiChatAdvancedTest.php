<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiChatAdvancedTest extends TestCase
{
    use DatabaseTransactions;

    protected $token;
    protected $userId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo và đăng nhập User
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
            'full_name' => 'Test Spender',
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
     * Test phản hồi trực tiếp (không gọi tool) trả về cấu trúc JSON chuẩn và được ghi vào DB
     */
    public function test_ai_chat_direct_response_json_and_db_logging()
    {
        $mockResponse = [
            'answer' => 'Chào bạn! Tôi có thể giúp gì cho bạn về tài chính hôm nay?',
            'insight' => 'Bạn chưa thiết lập hạn mức ngân sách nào cho tháng này.',
            'suggested_questions' => [
                'Làm sao thiết lập ngân sách?',
                'Xem ví của tôi có gì?',
                'Lợi ích của tiết kiệm là gì?'
            ]
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode($mockResponse, JSON_UNESCAPED_UNICODE)
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->postJson('/api/ai-chat', [
            'prompt' => 'Chào AI!'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('answer', $mockResponse['answer']);
        $response->assertJsonPath('insight', $mockResponse['insight']);
        $response->assertJsonPath('suggested_questions', $mockResponse['suggested_questions']);

        // Xác minh tin nhắn được ghi nhận vào DB
        $this->assertDatabaseHas('ai_chat_messages', [
            'user_id' => $this->userId,
            'role' => 'user',
            'content' => 'Chào AI!'
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'user_id' => $this->userId,
            'role' => 'model',
            'content' => json_encode($mockResponse, JSON_UNESCAPED_UNICODE)
        ]);
    }

    /**
     * Test trường hợp multi-turn (lượt chat tiếp theo mang theo ngữ cảnh cũ)
     */
    public function test_ai_chat_multi_turn_history_included_in_payload()
    {
        // 1. Chuẩn bị sẵn lịch sử chat trong DB
        DB::table('ai_chat_messages')->insert([
            [
                'id' => (string) Str::uuid(),
                'user_id' => $this->userId,
                'role' => 'user',
                'content' => 'Câu hỏi lượt 1',
                'function_name' => null,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinutes(2)
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $this->userId,
                'role' => 'model',
                'content' => json_encode(['answer' => 'Trả lời lượt 1', 'insight' => null, 'suggested_questions' => []]),
                'function_name' => null,
                'created_at' => now()->subMinutes(1),
                'updated_at' => now()->subMinutes(1)
            ]
        ]);

        $mockResponse2 = [
            'answer' => 'Trả lời lượt 2',
            'insight' => null,
            'suggested_questions' => ['Câu hỏi 1', 'Câu hỏi 2', 'Câu hỏi 3']
        ];

        // 2. Mock Gemini và kiểm tra xem payload gửi đi có chứa lịch sử không
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($mockResponse2) {
            $payload = json_decode($request->body(), true);
            $contents = $payload['contents'] ?? [];

            // Kiểm tra xem lịch sử có được gửi đi đúng thứ tự không
            // contents[0] -> User Câu hỏi lượt 1
            // contents[1] -> Model Trả lời lượt 1
            // contents[2] -> User Câu hỏi lượt 2 (prompt hiện tại)
            if (
                count($contents) === 3 &&
                $contents[0]['parts'][0]['text'] === 'Câu hỏi lượt 1' &&
                $contents[1]['parts'][0]['text'] === json_encode(['answer' => 'Trả lời lượt 1', 'insight' => null, 'suggested_questions' => []]) &&
                $contents[2]['parts'][0]['text'] === 'Câu hỏi lượt 2'
            ) {
                return Http::response([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'text' => json_encode($mockResponse2, JSON_UNESCAPED_UNICODE)
                                    ]
                                ]
                            ]
                        ]
                    ]
                ], 200);
            }

            return Http::response(['error' => 'Payload check failed'], 400);
        });

        $response = $this->postJson('/api/ai-chat', [
            'prompt' => 'Câu hỏi lượt 2'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('answer', $mockResponse2['answer']);
    }
}
