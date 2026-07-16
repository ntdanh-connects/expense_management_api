<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class AIDigestService
{
    public function __construct(protected FinancialAnalysisService $analytisService) {}

    public function getOrGenerateDigest(string $userId, string $periodStart, string $today, string $timezone): array
    {
        $lockKey = "ai_digest_lock:{$userId}:{$today}";
        $lockCache = Cache::lock($lockKey, 10);
        if ($lockCache->get()) {
            try {
                $existing = DB::table('ai_daily_digests')
                    ->where('user_id', $userId)
                    ->where('digest_date', $today)
                    ->first();
                if ($existing) {
                    return [
                        'summary' => $existing->summary,
                        'insight' => $existing->insight,
                        'suggested_questions' => json_decode($existing->suggested_questions, true)
                    ];
                }

                $cashflowData = $this->analytisService->getCashflowHistory($userId, $periodStart, $today, $timezone);

                $apiKey = env("GEMINI_API_KEY");
                $model = env('GEMINI_MODEL', 'gemini-2.5-flash');
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

                $systemInstruction = "Bạn là chuyên gia phân tích tài chính cá nhân. Nhiệm vụ của bạn là nhận dữ liệu dòng tiền hằng ngày của người dùng và trả về tóm tắt dưới dạng JSON"
                    . "QUY TẮT:\n"
                    . "- Không áp dụng các quy tắc chia % ngân sách cứng nhắc như 50/30/20"
                    . "\n- Đưa ra lời khuyên thực tế dựa trên số tiền chi tiêu thật\n"
                    . "- Trả về JSON theo định dạng bắt buộc:{ \n"
                    . "  \"summary\": \"Tóm tắt tình hình thu chi trong hôm nay và trong chu kì\",
            \"insight\": \"Lời khuyên hoặc cảnh báo (nullable)\",
            \"suggested_questions\": [\"câu hỏi 1\", \"câu hỏi 2\", \"câu hỏi 3\"]
        }";

                $payload = [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => "Dưới đây là dòng tiền tôi của tôi trong chu kì này: " . json_encode($cashflowData)]
                            ]
                        ]
                    ],
                    'system_instruction' => [
                        'parts' => [['text' => $systemInstruction]]
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => 1.5
                    ]
                ];

                $reponse = Http::timeout(15)->post($url, $payload);

                \Illuminate\Support\Facades\Log::info("Gemini API Response Status: " . $reponse->status());
                \Illuminate\Support\Facades\Log::info("Gemini API Response Body: " . $reponse->body());

                if ($reponse->successful()) {
                    $result = $reponse->json();
                    $rawText = $result['candidates'][0]['content']['parts']['0']['text'] ?? '{}';
                    $aiData = json_decode($rawText, true);

                    DB::table('ai_daily_digests')->insert([
                        'id' => (string) Str::uuid7(),
                        'user_id' => $userId,
                        'digest_date' => $today,
                        'summary' => $aiData['summary'] ?? 'Không có bản tóm tắt.',
                        'insight' => $aiData['insight'] ?? null,
                        'suggested_questions' => json_encode($aiData['suggested_questions'] ?? null),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    DB::table('ai_user_profiles')->updateOrInsert([
                        'user_id' => $userId
                    ], ['last_digest_date' => $today, 'updated_at' => now()]);

                    return [
                        'summary' => $aiData['summary'],
                        'insight' => $aiData['insight'] ?? null,
                        'suggested_questions' => $aiData['suggested_questions'] ?? []
                    ];
                }
            } finally {
                $lockCache->release();
            }
        }

        return ['summary' => 'Không thể tóm tắt tài chính lúc này.', 'insight' => null, 'suggested_questions' => null];
    }

    /**
     * Tự động kiểm tra và sinh cảnh báo nếu số dư ví tiền mặt thấp hơn thói quen chi tiêu hàng ngày.
     */
    public function generateHabitAlert(string $userId, string $walletId, float $transactionAmount): ?string
    {
        // 1. Lấy thông tin ví và số dư hiện tại
        $wallet = DB::table('wallets')->where('id', $walletId)->first();
        if (!$wallet) return null;

        $currentBalance = DB::table('wallet_balances')
            ->where('wallet_id', $walletId)
            ->value('available_balance') ?? 0;

        // 2. Lấy thói quen chi tiêu của user từ profile
        $profile = DB::table('ai_user_profiles')->where('user_id', $userId)->first();
        if (!$profile || !$profile->spending_persona) {
            // Nếu chưa có dữ liệu thói quen học được, tự động chạy phân tích ngay lập tức
            $analysisService = app(\App\Services\FinancialAnalysisService::class);
            $persona = $analysisService->learnUserSpendingHabits($userId);
        } else {
            $persona = json_decode($profile->spending_persona, true);
        }
        $dailyAvgCashSpend = $persona['average_daily_cash_spend'] ?? 0;

        if ($dailyAvgCashSpend <= 0) return null;

        // 3. Nếu là ví tiền mặt và số dư còn lại < thói quen chi tiêu hàng ngày
        if ($wallet->type === 'cash' && $currentBalance < $dailyAvgCashSpend) {
            $apiKey = env("GEMINI_API_KEY");
            if (!$apiKey) return null;
            
            $model = env('GEMINI_MODEL', 'gemini-2.5-flash');
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $prompt = "Bạn là trợ lý tài chính cá nhân thông minh và vui vẻ.
Ngữ cảnh người dùng:
- Ví đang sử dụng: {$wallet->name} (Loại ví: Tiền mặt).
- Thói quen chi tiêu tiền mặt trung bình mỗi ngày: " . number_format($dailyAvgCashSpend) . " VND.
- Giao dịch vừa thực hiện: Chi tiêu " . number_format($transactionAmount) . " VND.
- Số dư tiền mặt còn lại sau giao dịch: " . number_format($currentBalance) . " VND.

Yêu cầu:
1. Hãy viết một câu thông báo ngắn dưới 3 câu để nhắc nhở người dùng rằng số dư tiền mặt của họ hiện tại đang thấp hơn mức chi tiêu thói quen hằng ngày.
2. Khuyên họ chi tiêu thông thái hoặc nhắc họ đi rút thêm tiền mặt để dùng khi cần thiết.
3. Biến hóa câu từ linh hoạt, sử dụng emoji tự nhiên, xưng hô gần gũi như một người bạn thân nhắc nhở chi tiêu (không rập khuôn văn mẫu).
4. KHÔNG trả về định dạng JSON hay Markdown code block, chỉ trả về chuỗi văn bản thuần túy.";

            $payload = [
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.8
                ]
            ];

            try {
                $response = Http::timeout(10)->post($url, $payload);
                if ($response->successful()) {
                    $result = $response->json();
                    return $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Error generating habit alert: " . $e->getMessage());
            }
        }

        return null;
    }
}
