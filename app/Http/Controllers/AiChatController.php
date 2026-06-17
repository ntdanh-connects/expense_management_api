<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AiChatController extends Controller
{
    public function chat(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string',
            'user_id' => 'nullable|string'
        ]);

        $prompt = $request->input('prompt');
        $apiKey = env('GEMINI_API_KEY');
        $model = env('GEMINI_MODEL', 'gemini-3.5-flash');

        if (!$apiKey) {
            return response()->json([
                'error' => 'Chưa cấu hình GEMINI_API_KEY trong file .env'
            ], 500);
        }

        // Lấy user_id tự động từ token hoặc request
        $userId = $request->attributes->get('user_id');
        if (!$userId && $request->user()) {
            $userId = $request->user()->user_id;
        }
        if (!$userId) {
            $userId = $request->input('user_id');
        }
        if (!$userId) {
            $user = DB::table('users')->first();
            $userId = $user ? $user->user_id : '00000000-0000-0000-0000-000000000000';
        }

        // 1. Database Schema
        $systemInstruction = "Bạn là chuyên gia phân tích dữ liệu tài chính SQL. "
            . "Nhiệm vụ của bạn là nhận câu hỏi tự nhiên của người dùng và chuyển thành câu lệnh SQL PostgreSQL phù hợp.\n\n"
            . "Dưới đây là cấu trúc cơ sở dữ liệu (Database Schema):\n"
            . "1. Bảng `wallets` (Ví chứa tiền):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `user_id` (uuid, khóa ngoại)\n"
            . "   - `name` (varchar, tên ví như 'Ví MoMo', 'MB Bank')\n"
            . "   - `type` (enum: 'cash', 'bank', 'ewallet', 'crypto')\n"
            . "   - `currency_code` (varchar)\n"
            . "   - `deleted_at` (timestamptz)\n"
            . "2. Bảng `wallet_balances` (Số dư thực tế trong ví):\n"
            . "   - `wallet_id` (uuid, khóa chính, liên kết wallets.id)\n"
            . "   - `available_balance` (decimal, số tiền hiện có trong ví)\n"
            . "3. Bảng `categories` (Danh mục thu chi):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `user_id` (uuid, khóa ngoại. Bằng NULL đối với các danh mục mặc định của hệ thống)\n"
            . "   - `name` (varchar, tên danh mục như 'Ăn uống', 'Lương')\n"
            . "   - `type` (enum: 'income', 'expense')\n"
            . "   - `deleted_at` (timestamptz)\n"
            . "4. Bảng `transactions` (Giao dịch tài chính):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `user_id` (uuid, khóa ngoại)\n"
            . "   - `wallet_id` (uuid, khóa ngoại)\n"
            . "   - `category_id` (uuid, khóa ngoại)\n"
            . "   - `type` (enum: 'income', 'expense')\n"
            . "   - `status` (enum: 'pending', 'completed', 'failed', 'reverted')\n"
            . "   - `amount` (decimal, số tiền gốc của giao dịch)\n"
            . "   - `amount_in_user_currency` (decimal, số tiền quy đổi sang tiền tệ chính của người dùng)\n"
            . "   - `title` (varchar, tên/nội dung giao dịch)\n"
            . "   - `source_type` (enum: 'manual', 'recurring', 'transfer', 'import', 'adjustment')\n"
            . "   - `source_id` (uuid, ID nguồn của giao dịch liên kết với wallet_transfers.id)\n"
            . "   - `transaction_date` (timestamptz)\n"
            . "   - `deleted_at` (timestamptz)\n"
            . "5. Bảng `wallet_transfers` (Chuyển khoản nội bộ giữa các ví):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `from_wallet_id` (uuid)\n"
            . "   - `to_wallet_id` (uuid)\n"
            . "6. Bảng `budgets` (Ngân sách chi tiêu):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `user_id` (uuid)\n"
            . "   - `category_id` (uuid)\n"
            . "   - `limit_amount` (decimal, hạn mức chi tiêu tối đa)\n"
            . "   - `month` (integer, từ 1-12)\n"
            . "   - `year` (integer)\n"
            . "7. Bảng `budget_usages` (Mức sử dụng ngân sách thực tế):\n"
            . "   - `budget_id` (uuid, khóa chính, liên kết budgets.id)\n"
            . "   - `used_amount` (decimal, số tiền thực tế đã chi tiêu)\n"
            . "8. Bảng `recurring_rules` (Các giao dịch thiết lập định kỳ):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `user_id` (uuid)\n"
            . "   - `wallet_id` (uuid)\n"
            . "   - `category_id` (uuid)\n"
            . "   - `type` (enum: 'income', 'expense')\n"
            . "   - `amount` (decimal)\n"
            . "   - `title` (varchar)\n"
            . "   - `frequency` (enum: 'daily', 'weekly', 'monthly', 'yearly')\n"
            . "   - `interval_value` (integer)\n"
            . "   - `next_run_at` (timestamptz)\n"
            . "   - `end_at` (timestamptz)\n"
            . "   - `is_active` (bool)\n"
            . "   - `deleted_at` (timestamptz)\n\n"
            . "QUY TẮC BẮT BUỘC KHI VIẾT SQL (BẢO MẬT & ĐỘ CHÍNH XÁC):\n"
            . "1. BẢO MẬT USER: Người dùng hiện tại có ID là: '{$userId}'. Bạn BẮT BUỘC phải lọc theo điều kiện `user_id = '{$userId}'` cho TẤT CẢ các bảng để tránh lộ dữ liệu (ví dụ: `wallets.user_id = '{$userId}'`, `transactions.user_id = '{$userId}'`, `budgets.user_id = '{$userId}'`, `recurring_rules.user_id = '{$userId}'`). Đối với các bảng liên kết như `wallet_balances` và `budget_usages`, bạn phải join với bảng cha để lọc theo `user_id`. LƯU Ý RIÊNG CHO BẢNG `categories`: Bảng này chứa cả danh mục hệ thống (có `user_id IS NULL`) và danh mục riêng của user, nên bạn PHẢI dùng điều kiện: `(categories.user_id = '{$userId}' OR categories.user_id IS NULL)`.\n"
            . "2. SOFT DELETE: Chỉ lấy dữ liệu chưa xóa. Luôn thêm điều kiện `deleted_at IS NULL` cho các bảng `transactions`, `wallets`, `categories`, `recurring_rules`.\n"
            . "3. QUY ĐỔI TIỀN TỆ: Khi tính tổng số tiền (SUM, AVG) thu nhập/chi tiêu, bạn PHẢI dùng cột `amount_in_user_currency` của bảng `transactions` thay vì cột `amount`.\n"
            . "4. LOẠI BỎ CHUYỂN KHOẢN NỘI BỘ: Khi tính tổng thu nhập hoặc chi tiêu, bạn PHẢI loại trừ các giao dịch luân chuyển nội bộ (chuyển tiền giữa các ví của chính mình). Hãy thêm điều kiện lọc sau vào câu lệnh SQL của bạn:\n"
            . "   `AND (transactions.source_type != 'transfer' OR transactions.source_type IS NULL OR transactions.source_id NOT IN (SELECT wt.id FROM wallet_transfers wt JOIN wallets fw ON wt.from_wallet_id = fw.id JOIN wallets tw ON wt.to_wallet_id = tw.id WHERE fw.user_id = tw.user_id))`\n"
            . "5. THỜI GIAN & MÚI GIỜ: Ngày tháng trong `transaction_date` lưu theo giờ UTC. Người dùng ở múi giờ 'Asia/Ho_Chi_Minh' (+07:00). Khi tính theo tháng hiện tại, hãy dùng: `WHERE transaction_date >= DATE_TRUNC('month', CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Ho_Chi_Minh') AT TIME ZONE 'Asia/Ho_Chi_Minh' AND transaction_date < DATE_TRUNC('month', CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Ho_Chi_Minh') AT TIME ZONE 'Asia/Ho_Chi_Minh' + INTERVAL '1 month'` hoặc truy vấn tương đương.\n"
            . "6. Chỉ tạo câu lệnh SELECT an toàn. Không thực hiện các hành động sửa đổi cấu trúc hay dữ liệu.\n"
            . "7. XỬ LÝ LỖI GÕ PHÍM & TỪ VIẾT TẮT TIẾNG VIỆT: Người dùng thường nhắn tin nhanh bằng tiếng Việt không dấu, viết tắt (tui -> tôi, ko/k -> không, vs -> với) hoặc lỗi gõ Telex (ví dụ: gõ 'gì' thành 'gif' do phím f là dấu huyền nhưng chưa bật Telex, hoặc 'tiêu' thành 'tieeu', 'nhiều' thành 'nhieeu'). Bạn PHẢI tự động suy luận và chuẩn hóa các lỗi gõ Telex/từ viết tắt này thành nghĩa chuẩn tiếng Việt trước khi tạo câu lệnh SQL (ví dụ: 'tiêu vào cái gif nhiều nhất' thực chất nghĩa là 'tiêu vào cái gì nhiều nhất', bạn phải tạo SQL truy vấn tổng quát tìm danh mục chi tiêu nhiều nhất chứ không được lọc theo từ khóa 'gif').\n"
            . "8. XỬ LÝ YÊU CẦU TÓM TẮT/BÁO CÁO CHI TIÊU: Khi người dùng yêu cầu 'tóm tắt chi tiêu' hoặc 'báo cáo chi tiêu' (theo tuần, tháng, v.v.), bạn KHÔNG ĐƯỢC chỉ truy vấn tổng số tiền (SUM). Thay vào đó, bạn PHẢI truy vấn số tiền chi tiêu được nhóm theo từng danh mục (GROUP BY tên danh mục và tính SUM số tiền, sắp xếp giảm dần) hoặc lấy danh sách các giao dịch chi tiết để có dữ liệu phân tích cụ thể.\n"
            . "9. KHÔNG TRẢ VỀ SQL THÔ: Bạn KHÔNG ĐƯỢC phép trả về câu lệnh SQL thô dưới dạng văn bản (text) trực tiếp cho người dùng. Bạn bắt buộc phải gọi công cụ `execute_sql_query` để thực thi câu lệnh SQL đó.\n"
            . "10. TUYỆT ĐỐI CẤM HIỂN THỊ TRUY VẤN: Bạn tuyệt đối KHÔNG ĐƯỢC phép nhắc đến câu lệnh SQL, mã nguồn SQL, bảng biểu SQL hay bất kỳ cú pháp truy vấn nào (ví dụ: SELECT, FROM, WHERE, v.v.) trong phản hồi văn bản cuối cùng gửi cho người dùng. Không giải thích hay hiển thị câu lệnh SQL đã sử dụng. Người dùng chỉ quan tâm đến kết quả phân tích số liệu thực tế được trả về từ cơ sở dữ liệu.\n\n"
            . "- Sử dụng công cụ `execute_sql_query` để thực thi câu lệnh SQL PostgreSQL hợp lệ.";

        // 2. Định nghĩa Tool
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

        // 3. Gọi Gemini API (lượt 1)
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

        try {
            $response = Http::timeout(15)->post($url, $payload);
            
            if ($response->failed()) {
                return response()->json([
                    'error' => 'Lỗi gọi API Gemini lượt 1',
                    'details' => $response->json() ?? $response->body()
                ], 502);
            }

            $result = $response->json();
            $part = $result['candidates'][0]['content']['parts'][0] ?? null;

            if (!$part) {
                return response()->json([
                    'error' => 'Không có phản hồi từ Gemini',
                    'raw_response' => $result
                ], 502);
            }

            $sqlQuery = null;
            $dbResults = [];

            // 4. Nếu AI yêu cầu gọi Tool
            if (isset($part['functionCall'])) {
                $functionCall = $part['functionCall'];
                $functionName = $functionCall['name'];
                $args = $functionCall['args'];
                $sqlQuery = $args['sql_query'] ?? '';

                // Kiểm soát an toàn SQL
                if (!$this->isSqlQuerySafe($sqlQuery)) {
                    return response()->json([
                        'error' => 'Cảnh báo bảo mật: Câu lệnh SQL không an toàn.',
                        'sql_query' => $sqlQuery
                    ], 403);
                }

                // Thực thi SQL
                try {
                    $dbResults = DB::select($sqlQuery);
                } catch (\Exception $e) {
                    $dbResults = ['error' => $e->getMessage()];
                }

                // Gửi ngược kết quả cho Gemini (lượt 2)
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
                                            'result' => $dbResults
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

                $finalResponse = Http::timeout(15)->post($url, $finalPayload);

                if ($finalResponse->failed()) {
                    return response()->json([
                        'error' => 'Lỗi gọi API Gemini lượt 2',
                        'sql_query' => $sqlQuery,
                        'db_results' => $dbResults,
                        'details' => $finalResponse->json() ?? $finalResponse->body()
                    ], 502);
                }

                $finalResult = $finalResponse->json();
                $outputText = $finalResult['candidates'][0]['content']['parts'][0]['text'] ?? 'Không có câu trả lời.';

                return response()->json([
                    'success' => true,
                    'user_id' => $userId,
                    'prompt' => $prompt,
                    'model' => $model,
                    'sql_query' => $sqlQuery,
                    'db_results' => $dbResults,
                    'response' => $outputText
                ]);

            } else {
                // Nếu AI phản hồi trực tiếp không qua tool
                return response()->json([
                    'success' => true,
                    'user_id' => $userId,
                    'prompt' => $prompt,
                    'model' => $model,
                    'sql_query' => null,
                    'db_results' => [],
                    'response' => $part['text'] ?? 'Không có câu trả lời.'
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Lỗi xử lý server',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function isSqlQuerySafe(string $sql): bool
    {
        $sqlLower = strtolower(trim($sql));

        if (!str_starts_with($sqlLower, 'select') && !str_starts_with($sqlLower, 'with')) {
            return false;
        }

        $forbiddenKeywords = [
            'insert', 'update', 'delete', 'drop', 'truncate', 'alter',
            'create', 'grant', 'revoke', 'replace', 'vacuum', 'analyze',
            'into', 'union'
        ];

        foreach ($forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/', $sqlLower)) {
                return false;
            }
        }

        return true;
    }
}
