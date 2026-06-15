<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TestGeminiTextToSql extends Command
{
    protected $signature = 'app:test-gemini-text-to-sql {prompt : Câu hỏi tự nhiên của bạn}';
    protected $description = 'Nghiên cứu Gemini Tool Calling để biến câu hỏi tự nhiên thành SQL query và trả về câu trả lời';

    public function handle()
    {
        $prompt = $this->argument('prompt');
        $apiKey = env('GEMINI_API_KEY');
        $model = env('GEMINI_MODEL', 'gemini-3.5-flash');

        if (!$apiKey) {
            $this->error('Lỗi: Chưa cấu hình GEMINI_API_KEY trong file .env');
            return 1;
        }

        // 1. Lấy một user_id thực tế từ Database để giả lập đăng nhập
        $user = DB::table('users')->first();
        $userId = $user ? $user->user_id : '00000000-0000-0000-0000-000000000000';

        $this->info("-----------------------------------------------------------------");
        $this->info("👤 Giả lập Người dùng hiện tại có ID: " . $userId);
        $this->info("❓ Câu hỏi: \"{$prompt}\"");
        $this->info("🤖 Sử dụng Model: {$model}");
        $this->info("-----------------------------------------------------------------");

        // 2. Định nghĩa Database Schema chi tiết cho Gemini
        $systemInstruction = "Bạn là chuyên gia phân tích dữ liệu tài chính SQL. "
            . "Nhiệm vụ của bạn là nhận câu hỏi tự nhiên của người dùng và chuyển thành câu lệnh SQL PostgreSQL phù hợp.\n\n"
            . "Dưới đây là cấu trúc cơ sở dữ liệu (Database Schema):\n"
            . "1. Bảng `users`:\n"
            . "   - `user_id` (uuid, khóa chính)\n"
            . "   - `email` (varchar)\n"
            . "2. Bảng `wallets` (Ví chứa tiền):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `user_id` (uuid, khóa ngoại liên kết users.user_id)\n"
            . "   - `name` (varchar, tên ví)\n"
            . "   - `type` (enum: 'cash', 'bank', 'ewallet', 'crypto')\n"
            . "   - `currency_code` (varchar, ví dụ: 'VND', 'USD')\n"
            . "   - `deleted_at` (timestamptz)\n"
            . "3. Bảng `categories` (Danh mục thu chi):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `user_id` (uuid, khóa ngoại liên kết users.user_id)\n"
            . "   - `name` (varchar, tên danh mục)\n"
            . "   - `type` (enum: 'income' - thu nhập, 'expense' - chi tiêu)\n"
            . "   - `deleted_at` (timestamptz)\n"
            . "4. Bảng `transactions` (Giao dịch tài chính):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `user_id` (uuid, khóa ngoại liên kết users.user_id)\n"
            . "   - `wallet_id` (uuid, khóa ngoại liên kết wallets.id)\n"
            . "   - `category_id` (uuid, khóa ngoại liên kết categories.id)\n"
            . "   - `type` (enum: 'income', 'expense')\n"
            . "   - `status` (enum: 'pending', 'completed', 'failed', 'reverted')\n"
            . "   - `amount` (decimal, số tiền theo loại tiền tệ gốc của giao dịch)\n"
            . "   - `amount_in_user_currency` (decimal, số tiền đã quy đổi sang tiền tệ chính của người dùng)\n"
            . "   - `title` (varchar, tiêu đề giao dịch)\n"
            . "   - `source_type` (enum: 'manual', 'recurring', 'transfer', 'import', 'adjustment')\n"
            . "   - `transaction_date` (timestamptz, ngày thực hiện giao dịch)\n"
            . "   - `deleted_at` (timestamptz)\n"
            . "5. Bảng `wallet_transfers` (Chuyển khoản):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `from_wallet_id` (uuid, ví gửi)\n"
            . "   - `to_wallet_id` (uuid, ví nhận)\n\n"
            . "QUY TẮC BẮT BUỘC KHI VIẾT SQL (BẢO MẬT & ĐỘ CHÍNH XÁC):\n"
            . "1. BẢO MẬT USER: Người dùng hiện tại có ID là: '{$userId}'. Bạn BẮT BUỘC phải lọc theo điều kiện `user_id = '{$userId}'` cho TẤT CẢ các bảng để tránh lộ dữ liệu.\n"
            . "2. SOFT DELETE: Chỉ lấy dữ liệu chưa xóa. Luôn thêm điều kiện `deleted_at IS NULL` cho các bảng `transactions`, `wallets`, `categories`.\n"
            . "3. QUY ĐỔI TIỀN TỆ: Khi tính tổng số tiền (SUM, AVG) thu nhập/chi tiêu, bạn PHẢI dùng cột `amount_in_user_currency` của bảng `transactions` thay vì cột `amount`.\n"
            . "4. LOẠI BỎ CHUYỂN KHOẢN NỘI BỘ: Khi tính tổng thu nhập hoặc chi tiêu, bạn PHẢI loại trừ các giao dịch luân chuyển nội bộ (chuyển tiền giữa các ví của chính mình). Hãy thêm điều kiện lọc sau vào câu lệnh SQL của bạn:\n"
            . "   `AND (transactions.source_type != 'transfer' OR transactions.source_type IS NULL OR transactions.source_id NOT IN (SELECT wt.id FROM wallet_transfers wt JOIN wallets fw ON wt.from_wallet_id = fw.id JOIN wallets tw ON wt.to_wallet_id = tw.id WHERE fw.user_id = tw.user_id))`\n"
            . "5. THỜI GIAN & MÚI GIỜ: Ngày tháng trong `transaction_date` lưu theo giờ UTC. Người dùng ở múi giờ 'Asia/Ho_Chi_Minh' (+07:00). Khi tính theo tháng hiện tại, hãy dùng: `WHERE transaction_date >= DATE_TRUNC('month', CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Ho_Chi_Minh') AND transaction_date < DATE_TRUNC('month', CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Ho_Chi_Minh') + INTERVAL '1 month'` hoặc truy vấn tương đương.\n"
            . "6. Chỉ tạo câu lệnh SELECT an toàn. Không thực hiện các hành động sửa đổi cấu trúc hay dữ liệu.\n"
            . "- Trả về câu lệnh SQL viết bằng chuẩn PostgreSQL.";

        // 3. Định nghĩa Tool gọi SQL
        $tools = [
            [
                'function_declarations' => [
                    [
                        'name' => 'execute_sql_query',
                        'description' => 'Thực thi câu lệnh SQL SELECT PostgreSQL để lấy thông tin chi tiêu của người dùng.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'sql_query' => [
                                    'type' => 'STRING',
                                    'description' => 'Câu lệnh PostgreSQL SELECT hợp lệ đã được lọc theo user_id.'
                                ]
                            ],
                            'required' => ['sql_query']
                        ]
                    ]
                ]
            ]
        ];

        // 4. Gửi request đầu tiên đến Gemini
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]]
                ]
            ],
            'tools' => $tools,
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ]
        ];

        $this->comment("Đang gửi yêu cầu phân tích cho Gemini...");
        $response = Http::post($url, $payload);

        if ($response->failed()) {
            $this->error('Lỗi API Gemini: ' . $response->body());
            return 1;
        }

        $result = $response->json();
        $part = $result['candidates'][0]['content']['parts'][0] ?? null;

        if (!$part) {
            $this->error('Không nhận được phản hồi hợp lệ từ Gemini: ' . json_encode($result));
            return 1;
        }

        // 5. Xử lý yêu cầu gọi Tool từ Gemini
        if (isset($part['functionCall'])) {
            $functionCall = $part['functionCall'];
            $functionName = $functionCall['name'];
            $args = $functionCall['args'];
            $sqlQuery = $args['sql_query'] ?? '';

            $this->warn("🤖 AI đã sinh SQL Query: ");
            $this->line("   " . $sqlQuery);

            // Kiểm soát an toàn (Sandbox)
            if (!$this->isSqlQuerySafe($sqlQuery)) {
                $this->error("❌ CẢNH BÁO BẢO MẬT: Phát hiện câu lệnh SQL không an toàn hoặc chứa từ khóa sửa đổi dữ liệu!");
                return 1;
            }

            // Thực thi truy vấn
            try {
                $this->comment("⚙️ Đang thực thi truy vấn vào Database...");
                $queryResult = DB::select($sqlQuery);
                // Giới hạn hiển thị log để không làm rối màn hình
                $resultCount = count($queryResult);
                $this->info("✅ Đã tìm thấy {$resultCount} dòng kết quả.");
            } catch (\Exception $e) {
                $this->error("❌ Lỗi khi thực thi SQL: " . $e->getMessage());
                // Gửi thông báo lỗi cho AI để nó giải thích cho người dùng
                $queryResult = ['error' => $e->getMessage()];
            }

            // Gửi dữ liệu trả về cho Gemini để sinh câu trả lời tự nhiên
            $this->comment("Đang gửi dữ liệu kết quả cho Gemini tổng hợp...");
            $finalPayload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $prompt]]
                    ],
                    [
                        'role' => 'model',
                        'parts' => [$part]
                    ],
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'functionResponse' => [
                                    'name' => $functionName,
                                    'response' => [
                                        'result' => $queryResult
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'tools' => $tools,
                'system_instruction' => [
                    'parts' => [
                        ['text' => $systemInstruction]
                    ]
                ]
            ];

            $finalResponse = Http::post($url, $finalPayload);

            if ($finalResponse->failed()) {
                $this->error('Lỗi khi gửi kết quả cho Gemini: ' . $finalResponse->body());
                return 1;
            }

            $finalResult = $finalResponse->json();
            $outputText = $finalResult['candidates'][0]['content']['parts'][0]['text'] ?? 'Không có câu trả lời.';

            $this->line("");
            $this->info("✨ Trợ lý AI phản hồi:");
            $this->comment($outputText);

        } else {
            // Trường hợp Gemini trả lời trực tiếp mà không cần công cụ
            $this->line("");
            $this->info("✨ Trợ lý AI phản hồi trực tiếp:");
            $this->comment($part['text'] ?? 'Không có câu trả lời.');
        }

        return 0;
    }

    /**
     * Hàm kiểm tra an toàn câu lệnh SQL
     */
    private function isSqlQuerySafe(string $sql): bool
    {
        $sqlLower = strtolower(trim($sql));

        // Bắt buộc phải bắt đầu bằng lệnh SELECT
        if (!str_starts_with($sqlLower, 'select') && !str_starts_with($sqlLower, 'with')) {
            return false;
        }

        // Danh sách các từ khóa nguy hại/sửa đổi dữ liệu
        $forbiddenKeywords = [
            'insert', 'update', 'delete', 'drop', 'truncate', 'alter',
            'create', 'grant', 'revoke', 'replace', 'vacuum', 'analyze',
            'into', 'union' // Union cũng có thể được dùng để SQL injection lấy bảng khác
        ];

        foreach ($forbiddenKeywords as $keyword) {
            // Dùng biểu thức chính quy để kiểm tra từ độc lập, tránh chặn các từ ghép vô tội như "created_at" hay "updated_at"
            if (preg_match('/\b' . $keyword . '\b/', $sqlLower)) {
                return false;
            }
        }

        return true;
    }
}
