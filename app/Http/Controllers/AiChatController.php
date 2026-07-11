<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    public function chat(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string',
            'user_id' => 'nullable|string',
            'conversation_id' => 'nullable|uuid'
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

        // Kiểm tra hoặc tạo cuộc hội thoại
        $conversationId = $request->input('conversation_id');
        if ($conversationId) {
            $conversation = DB::table('ai_conversations')
                ->where('id', $conversationId)
                ->where('user_id', $userId)
                ->first();

            if (!$conversation) {
                return response()->json([
                    'error' => 'Cuộc hội thoại không tồn tại hoặc không thuộc quyền sở hữu của bạn.'
                ], 404);
            }

            // Cập nhật updated_at để sắp xếp lên đầu
            DB::table('ai_conversations')
                ->where('id', $conversationId)
                ->update(['updated_at' => now()]);
        } else {
            $conversationId = (string) Str::uuid7();
            $title = Str::limit($prompt, 40, '...');
            DB::table('ai_conversations')->insert([
                'id' => $conversationId,
                'user_id' => $userId,
                'title' => $title,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // 1. Xác định timezone của người dùng và Lấy thông tin tài chính nền (Proactive Context)
        $userTimezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
        $userNow = now($userTimezone);
        $currentMonth = $userNow->month;
        $currentYear = $userNow->year;
        
        $budgetSummary = DB::table('budgets')
            ->join('budget_usages', 'budgets.id', '=', 'budget_usages.budget_id')
            ->join('categories', 'budgets.category_id', '=', 'categories.id')
            ->where('budgets.user_id', $userId)
            ->where('budgets.month', $currentMonth)
            ->where('budgets.year', $currentYear)
            ->select('categories.name as category_name', 'budgets.limit_amount', 'budget_usages.used_amount')
            ->get();

        $budgetContext = "";
        if ($budgetSummary->isNotEmpty()) {
            $budgetContext = "Ngân sách tháng {$currentMonth}/{$currentYear} của người dùng:\n";
            foreach ($budgetSummary as $b) {
                $percentage = $b->limit_amount > 0 ? round(($b->used_amount / $b->limit_amount) * 100, 1) : 0;
                $budgetContext .= "- Danh mục '{$b->category_name}': Hạn mức " . number_format($b->limit_amount) . " VND, đã chi " . number_format($b->used_amount) . " VND ({$percentage}%).\n";
            }
        } else {
            $budgetContext = "Người dùng chưa thiết lập ngân sách chi tiêu nào cho tháng {$currentMonth}/{$currentYear}.\n";
        }

        $savingGoals = DB::table('savings_goals')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->select('name', 'target_amount', 'current_amount')
            ->get();

        $goalsContext = "";
        if ($savingGoals->isNotEmpty()) {
            $goalsContext = "Các mục tiêu tiết kiệm đang hoạt động của người dùng:\n";
            foreach ($savingGoals as $g) {
                $percentage = $g->target_amount > 0 ? round(($g->current_amount / $g->target_amount) * 100, 1) : 0;
                $goalsContext .= "- Mục tiêu '{$g->name}': Đã tích lũy " . number_format($g->current_amount) . " / " . number_format($g->target_amount) . " VND ({$percentage}%).\n";
            }
        } else {
            $goalsContext = "Người dùng không có mục tiêu tiết kiệm nào đang hoạt động.\n";
        }

        $proactiveContext = "\n\n--- THÔNG TIN NỀN TÀI CHÍNH CỦA NGƯỜI DÙNG ---\n"
            . $budgetContext
            . $goalsContext
            . "--------------------------------------------\n"
            . "Hãy chủ động sử dụng các thông tin nền trên để đưa ra các phân tích, cảnh báo hoặc gợi ý tiết kiệm cá nhân hóa, thực tế khi người dùng hỏi các câu hỏi liên quan đến tình hình tài chính hoặc lời khuyên chi tiêu.";

        // 2. Database Schema và System Instructions
        $systemInstruction = "Bạn là chuyên gia phân tích dữ liệu tài chính SQL. "
            . "Nhiệm vụ của bạn là nhận câu hỏi tự nhiên của người dùng và chuyển thành câu lệnh SQL PostgreSQL phù hợp.\n\n"
            . "YÊU CẦU THỜI GIAN & MÚI GIỜ (CỰC KỲ QUAN TRỌNG):\n"
            . "- Múi giờ hiện tại của người dùng là: '{$userTimezone}'.\n"
            . "- Thời gian hiện tại của người dùng là: '{$userNow->toDateTimeString()}'.\n"
            . "- Ngày hôm nay của người dùng: '{$userNow->toDateString()}'.\n"
            . "- Cột `transaction_date` trong bảng `transactions` được lưu ở múi giờ UTC.\n"
            . "- Khi tạo câu lệnh SQL truy vấn thời gian (như hôm nay, hôm qua, tuần này, tháng này, năm nay), bạn BẮT BUỘC phải quy đổi múi giờ của cột `transaction_date` từ UTC sang múi giờ của người dùng trước khi so sánh.\n"
            . "- Ví dụ lọc ngày hôm nay: `WHERE (transaction_date AT TIME ZONE 'UTC' AT TIME ZONE '{$userTimezone}')::date = '{$userNow->toDateString()}'`.\n"
            . "- Ví dụ lọc tháng này: `WHERE DATE_TRUNC('month', transaction_date AT TIME ZONE 'UTC' AT TIME ZONE '{$userTimezone}') = DATE_TRUNC('month', '{$userNow->toDateString()}'::date)`.\n\n"
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
            . "   - `deleted_at` (timestamptz)\n"
            . "9. Bảng `savings_goals` (Mục tiêu tiết kiệm):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `user_id` (uuid, khóa ngoại)\n"
            . "   - `name` (varchar, tên mục tiêu tiết kiệm như 'Đi du lịch', 'Mua xe')\n"
            . "   - `target_amount` (decimal, số tiền mục tiêu cần tích lũy)\n"
            . "   - `current_amount` (decimal, số tiền đã tích lũy hiện tại)\n"
            . "   - `target_date` (date, ngày dự kiến hoàn thành)\n"
            . "   - `status` (string, trạng thái: 'active', 'completed', 'cancelled')\n"
            . "   - `auto_save_frequency` (string, tần suất tự động tích lũy: 'daily', 'weekly', 'monthly', hoặc null)\n"
            . "   - `auto_save_amount` (decimal, số tiền tự động tích lũy mỗi kỳ)\n"
            . "   - `source_wallet_id` (uuid, ví nguồn để tự động trích tiền)\n"
            . "10. Bảng `savings_transactions` (Giao dịch tích lũy hoặc rút tiền từ mục tiêu tiết kiệm):\n"
            . "   - `id` (uuid, khóa chính)\n"
            . "   - `savings_goal_id` (uuid, khóa ngoại liên kết savings_goals.id)\n"
            . "   - `type` (enum: 'deposit', 'withdraw')\n"
            . "   - `amount` (decimal, số tiền giao dịch)\n"
            . "   - `source_wallet_id` (uuid, ví nguồn/đích liên quan)\n"
            . "   - `transaction_date` (timestamptz)\n\n"
            . "QUY TẮC BẮT BUỘC KHI VIẾT SQL (BẢO MẬT & ĐỘ CHÍNH XÁC):\n"
            . "1. BẢO MẬT USER: Người dùng hiện tại có ID là: '{$userId}'. Bạn BẮT BUỘC phải lọc theo điều kiện `user_id = '{$userId}'` cho TẤT CẢ các bảng để tránh lộ dữ liệu (ví dụ: `wallets.user_id = '{$userId}'`, `transactions.user_id = '{$userId}'`, `budgets.user_id = '{$userId}'`, `recurring_rules.user_id = '{$userId}'`, `savings_goals.user_id = '{$userId}'`). Đối với các bảng liên kết như `wallet_balances`, `budget_usages` và `savings_transactions`, bạn phải join với bảng cha tương ứng để lọc theo `user_id`. LƯU Ý RIÊNG CHO BẢNG `categories`: Bảng này chứa cả danh mục hệ thống (có `user_id IS NULL`) và danh mục riêng của user, nên bạn PHẢI dùng điều kiện: `(categories.user_id = '{$userId}' OR categories.user_id IS NULL)`.\n"
            . "2. SOFT DELETE: Chỉ lấy dữ liệu chưa xóa. Luôn thêm điều kiện `deleted_at IS NULL` cho các bảng `transactions`, `wallets`, `categories`, `recurring_rules`.\n"
            . "3. QUY ĐỔI TIỀN TỆ: Khi tính tổng số tiền (SUM, AVG) thu nhập/chi tiêu, bạn PHẢI dùng cột `amount_in_user_currency` của bảng `transactions` thay vì cột `amount`.\n"
            . "4. LOẠI BỎ CHUYỂN KHOẢN NỘI BỘ: Khi tính tổng thu nhập hoặc chi tiêu, bạn PHẢI loại trừ các giao dịch luân chuyển nội bộ (chuyển tiền giữa các ví của chính mình). Hãy thêm điều kiện lọc sau vào câu lệnh SQL của bạn:\n"
            . "   `AND (transactions.source_type != 'transfer' OR transactions.source_type IS NULL OR transactions.source_id NOT IN (SELECT wt.id FROM wallet_transfers wt JOIN wallets fw ON wt.from_wallet_id = fw.id JOIN wallets tw ON wt.to_wallet_id = tw.id WHERE fw.user_id = tw.user_id))`\n"
            . "5. THỜI GIAN & MÚI GIỜ: Ngày tháng trong `transaction_date` lưu theo giờ UTC. Người dùng ở múi giờ 'Asia/Ho_Chi_Minh' (+07:00). Khi tính theo tháng hiện tại, hãy dùng: `WHERE transaction_date >= DATE_TRUNC('month', CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Ho_Chi_Minh') AT TIME ZONE 'Asia/Ho_Chi_Minh' AND transaction_date < DATE_TRUNC('month', CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Ho_Chi_Minh') AT TIME ZONE 'Asia/Ho_Chi_Minh' + INTERVAL '1 month'` hoặc truy vấn tương đương.\n"
            . "6. Chỉ tạo câu lệnh SELECT an toàn. Không thực hiện các hành động sửa đổi cấu trúc hay dữ liệu.\n"
            . "7. XỬ LÝ LỖI GÕ PHÍM & TỪ VIẾT TẮT TIẾNG VIỆT: Tự động suy luận và chuẩn hóa các lỗi gõ Telex/từ viết tắt này thành nghĩa chuẩn tiếng Việt trước khi tạo câu lệnh SQL (ví dụ: 'tiêu vào cái gif nhiều nhất' -> 'tiêu vào cái gì nhiều nhất').\n"
            . "8. XỬ LÝ YÊU CẦU TÓM TẮT/BÁO CÁO CHI TIÊU: Khi người dùng yêu cầu 'tóm tắt chi tiêu' hoặc 'báo cáo chi tiêu' (theo tuần, tháng, v.v.), bạn KHÔNG ĐƯỢC chỉ truy vấn tổng số tiền (SUM). Thay vào đó, bạn PHẢI truy vấn số tiền chi tiêu được nhóm theo từng danh mục (GROUP BY tên danh mục và tính SUM số tiền, sắp xếp giảm dần) hoặc lấy danh sách các giao dịch chi tiết để có dữ liệu phân tích cụ thể.\n"
            . "9. KHÔNG TRẢ VỀ SQL THÔ: Bạn KHÔNG ĐƯỢC phép trả về câu lệnh SQL thô dưới dạng văn bản (text) trực tiếp cho người dùng. Bạn bắt buộc phải gọi công cụ `execute_sql_query` để thực thi câu lệnh SQL đó.\n"
            . "10. TUYỆT ĐỐI CẤM HIỂN THỊ TRUY VẤN: Bạn tuyệt đối KHÔNG ĐƯỢC phép nhắc đến câu lệnh SQL, mã nguồn SQL, bảng biểu SQL hay bất kỳ cú pháp truy vấn nào (ví dụ: SELECT, FROM, WHERE, v.v.) trong phản hồi văn bản cuối cùng gửi cho người dùng. Không giải thích hay hiển thị câu lệnh SQL đã sử dụng.\n\n"
            . "YÊU CẦU ĐỊNH DẠNG ĐẦU RA (JSON MODE):\n"
            . "Khi trả về câu trả lời cuối cùng cho người dùng (sau khi đã có kết quả SQL hoặc đối thoại bình thường), bạn BẮT BUỘC phải trả về định dạng JSON hợp lệ chứa các trường sau:\n"
            . "{\n"
            . "  \"answer\": \"Nội dung câu trả lời hoàn chỉnh, chi tiết bằng tiếng Việt. Không chứa markdown JSON hay thẻ ```json, chỉ trả về chuỗi JSON thô.\",\n"
            . "  \"insight\": \"Lời khuyên tài chính ngắn gọn, cá nhân hóa dựa trên dữ liệu ngân sách hoặc mục tiêu tiết kiệm của người dùng (ví dụ: khuyên tiết kiệm, cảnh báo chi tiêu). Nếu không có insight nào phù hợp, đặt giá trị null.\",\n"
            . "  \"suggested_questions\": [\n"
            . "     \"Câu hỏi gợi ý 1 liên quan mật thiết đến chủ đề đang trò chuyện,\",\n"
            . "     \"Câu hỏi gợi ý 2\",\n"
            . "     \"Câu hỏi gợi ý 3\"\n"
            . "  ]\n"
            . "}\n"
            . "Lưu ý: suggested_questions phải chứa chính xác 3 câu hỏi gợi ý tiếp theo có ích nhất cho người dùng.\n\n"
            . "- Sử dụng công cụ `execute_sql_query` để thực thi câu lệnh SQL PostgreSQL hợp lệ."
            . $proactiveContext;

        // 3. Định nghĩa Tool
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

        // 4. Lấy lịch sử hội thoại gần nhất của cuộc trò chuyện này
        $history = DB::table('ai_chat_messages')
            ->where('user_id', $userId)
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at', 'desc')
            ->take(15)
            ->get()
            ->reverse();

        $contents = [];
        foreach ($history as $msg) {
            if ($msg->role === 'user') {
                $contents[] = [
                    'role' => 'user',
                    'parts' => [['text' => $msg->content]]
                ];
            } elseif ($msg->role === 'model') {
                if ($msg->function_name) {
                    $contents[] = [
                        'role' => 'model',
                        'parts' => [[
                            'functionCall' => [
                                'name' => $msg->function_name,
                                'args' => json_decode($msg->content, true) ?: []
                            ]
                        ]]
                    ];
                } else {
                    $contents[] = [
                        'role' => 'model',
                        'parts' => [['text' => $msg->content]]
                    ];
                }
            } elseif ($msg->role === 'function') {
                $contents[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $msg->function_name,
                            'response' => [
                                'result' => json_decode($msg->content, true) ?: []
                            ]
                        ]
                    ]]
                ];
            }
        }

        // Thêm tin nhắn hiện tại của người dùng vào cuối contents và lưu DB
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $prompt]]
        ];

        DB::table('ai_chat_messages')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => $prompt,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 5. Gọi Gemini API (lượt 1)
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        
        $payload = [
            'contents' => $contents,
            'tools' => $tools,
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
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

            // 6. Nếu AI yêu cầu gọi Tool
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

                // Lưu model functionCall vào DB
                DB::table('ai_chat_messages')->insert([
                    'id' => (string) Str::uuid7(),
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                    'role' => 'model',
                    'content' => json_encode($args, JSON_UNESCAPED_UNICODE),
                    'function_name' => $functionName,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Thực thi SQL
                try {
                    $dbResults = DB::select($sqlQuery);
                } catch (\Exception $e) {
                    $dbResults = ['error' => $e->getMessage()];
                }

                // Lưu functionResponse vào DB
                DB::table('ai_chat_messages')->insert([
                    'id' => (string) Str::uuid7(),
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                    'role' => 'function',
                    'content' => json_encode($dbResults, JSON_UNESCAPED_UNICODE),
                    'function_name' => $functionName,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Gửi ngược kết quả cho Gemini (lượt 2)
                $finalContents = $contents;
                $finalContents[] = [
                    'role' => 'model',
                    'parts' => [$part]
                ];
                $finalContents[] = [
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
                ];

                $finalPayload = [
                    'contents' => $finalContents,
                    'system_instruction' => [
                        'parts' => [
                            ['text' => $systemInstruction]
                        ]
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
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
                $outputText = $finalResult['candidates'][0]['content']['parts'][0]['text'] ?? '{"answer": "Không có câu trả lời."}';

                // Lưu model finalResponse vào DB
                DB::table('ai_chat_messages')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                    'role' => 'model',
                    'content' => $outputText,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                $geminiResponse = json_decode($outputText, true);
                $answer = $geminiResponse['answer'] ?? $outputText;
                $insight = $geminiResponse['insight'] ?? null;
                $suggestedQuestions = $geminiResponse['suggested_questions'] ?? [];

                return response()->json([
                    'success' => true,
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                    'prompt' => $prompt,
                    'model' => $model,
                    'sql_query' => $sqlQuery,
                    'db_results' => $dbResults,
                    'answer' => $answer,
                    'insight' => $insight,
                    'suggested_questions' => $suggestedQuestions
                ]);

            } else {
                // Nếu AI phản hồi trực tiếp không qua tool
                $rawText = $part['text'] ?? '{"answer": "Không có câu trả lời."}';

                DB::table('ai_chat_messages')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                    'role' => 'model',
                    'content' => $rawText,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                $geminiResponse = json_decode($rawText, true);
                $answer = $geminiResponse['answer'] ?? $rawText;
                $insight = $geminiResponse['insight'] ?? null;
                $suggestedQuestions = $geminiResponse['suggested_questions'] ?? [];

                return response()->json([
                    'success' => true,
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                    'prompt' => $prompt,
                    'model' => $model,
                    'sql_query' => null,
                    'db_results' => [],
                    'answer' => $answer,
                    'insight' => $insight,
                    'suggested_questions' => $suggestedQuestions
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Lỗi xử lý server',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function listConversations(Request $request)
    {
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

        $conversations = DB::table('ai_conversations')
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'conversations' => $conversations
        ]);
    }

    public function listMessages(Request $request, $id)
    {
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

        $conversation = DB::table('ai_conversations')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$conversation) {
            return response()->json([
                'error' => 'Cuộc hội thoại không tồn tại hoặc không thuộc quyền sở hữu của bạn.'
            ], 404);
        }

        $messages = DB::table('ai_chat_messages')
            ->where('conversation_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
            'messages' => $messages
        ]);
    }

    public function updateConversation(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255'
        ]);

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

        $conversation = DB::table('ai_conversations')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$conversation) {
            return response()->json([
                'error' => 'Cuộc hội thoại không tồn tại hoặc không thuộc quyền sở hữu của bạn.'
            ], 404);
        }

        DB::table('ai_conversations')
            ->where('id', $id)
            ->update([
                'title' => $request->input('title'),
                'updated_at' => now()
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật tiêu đề cuộc hội thoại thành công.'
        ]);
    }

    public function deleteConversation(Request $request, $id)
    {
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

        $conversation = DB::table('ai_conversations')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$conversation) {
            return response()->json([
                'error' => 'Cuộc hội thoại không tồn tại hoặc không thuộc quyền sở hữu của bạn.'
            ], 404);
        }

        // Xóa cứng khỏi database
        DB::table('ai_conversations')
            ->where('id', $id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa cuộc hội thoại thành công.'
        ]);
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
