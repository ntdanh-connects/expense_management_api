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
                $model = env('GEMINI_API_MODEL', 'gemini-1.5-flash');
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

                $reponse = Http::post($url, $payload);

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
}
