<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class AIHabitAnalysisService
{
    /**
     * Thực hiện phân tích hàng ngày cho người dùng
     */
    public function generateDailyAnalysis(string $userId, Carbon $targetDate)
    {
        $analysisDateStr = $targetDate->format('Y-m-d');
        $periodRange = "Ngày " . $targetDate->format('d/m/Y');

        // Lấy timezone của user để truy vấn chính xác
        $timezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';

        // 1. Tính chi tiêu thực tế ngày hôm nay (Target date)
        $startOfDay = $targetDate->copy()->startOfDay()->timezone($timezone)->utc();
        $endOfDay = $targetDate->copy()->endOfDay()->timezone($timezone)->utc();

        $actualAmount = (float) DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startOfDay, $endOfDay])
            ->whereNull('deleted_at')
            ->sum('amount_in_user_currency');

        // 2. Tính chi tiêu trung bình 30 ngày trước đó (không gồm ngày mục tiêu)
        $past30DaysStart = $targetDate->copy()->subDays(30)->startOfDay()->timezone($timezone)->utc();
        $past30DaysEnd = $targetDate->copy()->subDay()->endOfDay()->timezone($timezone)->utc();

        $totalPastSpending = (float) DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$past30DaysStart, $past30DaysEnd])
            ->whereNull('deleted_at')
            ->sum('amount_in_user_currency');

        $baselineAmount = round($totalPastSpending / 30, 2);

        $diffAmount = $actualAmount - $baselineAmount;
        
        if ($baselineAmount > 0) {
            $percentChange = round(($diffAmount / $baselineAmount) * 100, 2);
        } else {
            $percentChange = $actualAmount > 0 ? 100.00 : 0.00;
        }

        // Xác định trạng thái
        if ($percentChange > 50) {
            $status = 'overspending';
        } elseif ($percentChange < -50) {
            $status = 'saving';
        } else {
            $status = 'normal';
        }

        // 3. Gọi Gemini AI để phân tích nếu phát sinh chi tiêu hôm nay
        $aiInsight = "";
        if ($actualAmount > 0) {
            // Lấy chi tiết các giao dịch hôm nay để đưa vào Prompt
            $todayTransactions = DB::table('transactions')
                ->where('user_id', $userId)
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$startOfDay, $endOfDay])
                ->whereNull('deleted_at')
                ->get(['title', 'amount_in_user_currency', 'notes']);

            $prompt = "Hôm nay tôi đã chi tiêu tổng cộng: " . number_format($actualAmount, 0) . " VND.\n";
            $prompt .= "Trung bình chi tiêu 30 ngày qua của tôi là: " . number_format($baselineAmount, 0) . " VND.\n";
            $prompt .= "Độ lệch chi tiêu: " . ($percentChange >= 0 ? '+' : '') . $percentChange . "%.\n";
            $prompt .= "Danh sách các khoản chi tiêu hôm nay:\n";
            foreach ($todayTransactions as $tx) {
                $prompt .= "- {$tx->title}: " . number_format($tx->amount_in_user_currency, 0) . " VND" . ($tx->notes ? " ({$tx->notes})" : "") . "\n";
            }

            $systemInstruction = "Bạn là chuyên gia phân tích tài chính cá nhân. Hãy phân tích chi tiêu hôm nay của người dùng so với trung bình 30 ngày qua.\n"
                . "Hãy viết một báo cáo súc tích trong 3-4 câu bao gồm:\n"
                . "1. Tổng kết chi tiêu trong ngày: Đánh giá nhanh mức tăng/giảm chi tiêu hôm nay so với trung bình.\n"
                . "2. Danh mục nổi bật: Chỉ ra những danh mục hoặc khoản chi tiêu nhiều nhất hôm nay.\n"
                . "3. Giao dịch bất thường: Nhận diện bất kỳ giao dịch nào lớn đột biến hoặc có dấu hiệu bất thường cần lưu ý (nếu có).\n"
                . "Hãy phản hồi bằng Tiếng Việt với giọng điệu khách quan, chuyên nghiệp, mang tính xây dựng.";

            $aiInsight = $this->callGemini($prompt, $systemInstruction);
        } else {
            $aiInsight = "Hôm nay bạn không phát sinh khoản chi tiêu nào. Một ngày tuyệt vời để bảo toàn ngân sách của bạn!";
        }

        if ($aiInsight && !str_starts_with($aiInsight, 'Lỗi')) {
            $this->saveAnalysis($userId, 'daily', $analysisDateStr, $periodRange, $baselineAmount, $actualAmount, $diffAmount, $percentChange, $status, $aiInsight);
        } else {
            Log::warning("Bỏ qua lưu phân tích daily cho user {$userId} do lỗi AI: {$aiInsight}");
        }
    }

    /**
     * Thực hiện phân tích hàng tháng cho người dùng
     */
    public function generateMonthlyAnalysis(string $userId, Carbon $targetDate)
    {
        $timezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
        
        $currentMonthStart = $targetDate->copy()->startOfMonth()->timezone($timezone)->utc();
        $currentMonthEnd = $targetDate->copy()->endOfMonth()->timezone($timezone)->utc();

        $prevMonthStart = $targetDate->copy()->subMonth()->startOfMonth()->timezone($timezone)->utc();
        $prevMonthEnd = $targetDate->copy()->subMonth()->endOfMonth()->timezone($timezone)->utc();

        $analysisDateStr = $targetDate->format('Y-m-d');
        $periodRange = "Tháng " . $targetDate->format('m/Y');

        // 1. Tổng chi tiêu tháng này
        $actualAmount = (float) DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$currentMonthStart, $currentMonthEnd])
            ->whereNull('deleted_at')
            ->sum('amount_in_user_currency');

        // 2. Tổng chi tiêu tháng trước
        $baselineAmount = (float) DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$prevMonthStart, $prevMonthEnd])
            ->whereNull('deleted_at')
            ->sum('amount_in_user_currency');

        $diffAmount = $actualAmount - $baselineAmount;
        
        if ($baselineAmount > 0) {
            $percentChange = round(($diffAmount / $baselineAmount) * 100, 2);
        } else {
            $percentChange = $actualAmount > 0 ? 100.00 : 0.00;
        }

        if ($percentChange > 10) {
            $status = 'overspending';
        } elseif ($percentChange < -10) {
            $status = 'saving';
        } else {
            $status = 'normal';
        }

        // Lấy top danh mục chi tiêu trong tháng này để AI phân tích
        $topCategories = DB::table('transactions')
            ->join('categories', 'transactions.category_id', '=', 'categories.id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            ->whereBetween('transactions.transaction_date', [$currentMonthStart, $currentMonthEnd])
            ->whereNull('transactions.deleted_at')
            ->select('categories.name', DB::raw('SUM(transactions.amount_in_user_currency) as total_amount'))
            ->groupBy('categories.name')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get();

        // Lấy danh mục vượt hạn mức ngân sách tháng này
        $overBudgets = DB::table('budgets')
            ->join('budget_usages', 'budgets.id', '=', 'budget_usages.budget_id')
            ->join('categories', 'budgets.category_id', '=', 'categories.id')
            ->where('budgets.user_id', $userId)
            ->where('budgets.month', $targetDate->month)
            ->where('budgets.year', $targetDate->year)
            ->whereRaw('budget_usages.used_amount > budgets.limit_amount')
            ->select('categories.name', 'budgets.limit_amount', 'budget_usages.used_amount')
            ->get();

        // Lấy tổng thu nhập tháng này để tính xu hướng tiết kiệm
        $totalIncome = (float) DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$currentMonthStart, $currentMonthEnd])
            ->whereNull('deleted_at')
            ->sum('amount_in_user_currency');

        $prompt = "Tổng chi tiêu tháng này ({$periodRange}): " . number_format($actualAmount, 0) . " VND.\n";
        $prompt .= "Tổng chi tiêu tháng trước: " . number_format($baselineAmount, 0) . " VND.\n";
        $prompt .= "Độ lệch chi tiêu Month-over-Month (MoM): " . ($percentChange >= 0 ? '+' : '') . $percentChange . "%.\n";
        $prompt .= "Tổng thu nhập tháng này: " . number_format($totalIncome, 0) . " VND.\n";
        $prompt .= "Top 5 danh mục chi tiêu lớn nhất tháng này:\n";
        foreach ($topCategories as $cat) {
            $prompt .= "- {$cat->name}: " . number_format($cat->total_amount, 0) . " VND\n";
        }
        if ($overBudgets->isNotEmpty()) {
            $prompt .= "Danh mục vượt hạn mức ngân sách tháng này:\n";
            foreach ($overBudgets as $ob) {
                $prompt .= "- {$ob->name}: Thực tế tiêu " . number_format($ob->used_amount, 0) . " VND / Hạn mức " . number_format($ob->limit_amount, 0) . " VND\n";
            }
        } else {
            $prompt .= "Không có danh mục nào chi tiêu vượt hạn mức ngân sách.\n";
        }

        $systemInstruction = "Bạn là cố vấn tài chính cá nhân chuyên nghiệp. Hãy viết báo cáo phân tích chi tiêu hàng tháng cho người dùng bằng Tiếng Việt gồm 3 phần:\n"
            . "1. So sánh chi tiêu với tháng trước: Phân tích biến động tăng giảm tổng quan MoM.\n"
            . "2. Xu hướng tiết kiệm: Đánh giá tỷ lệ tiết kiệm dựa trên tổng thu nhập và chi tiêu tháng này.\n"
            . "3. Cảnh báo vượt ngân sách: Nhận định và đưa ra lời khuyên thiết thực để giảm chi phí ở những danh mục chi tiêu vượt hạn mức hoặc chiếm tỷ trọng quá lớn.\n"
            . "Yêu cầu: Viết súc tích trong 4-5 câu ngắn gọn, có lời khuyên cụ thể, hành động được.";

        $aiInsight = $this->callGemini($prompt, $systemInstruction);

        if ($aiInsight && !str_starts_with($aiInsight, 'Lỗi')) {
            $this->saveAnalysis($userId, 'monthly', $analysisDateStr, $periodRange, $baselineAmount, $actualAmount, $diffAmount, $percentChange, $status, $aiInsight);
        } else {
            Log::warning("Bỏ qua lưu phân tích monthly cho user {$userId} do lỗi AI: {$aiInsight}");
        }
    }

    /**
     * Thực hiện phân tích hàng năm cho người dùng
     */
    public function generateYearlyAnalysis(string $userId, Carbon $targetDate)
    {
        $timezone = DB::table('user_preferences')->where('user_id', $userId)->value('timezone') ?? 'Asia/Ho_Chi_Minh';
        
        $currentYearStart = $targetDate->copy()->startOfYear()->timezone($timezone)->utc();
        $currentYearEnd = $targetDate->copy()->endOfYear()->timezone($timezone)->utc();

        $prevYearStart = $targetDate->copy()->subYear()->startOfYear()->timezone($timezone)->utc();
        $prevYearEnd = $targetDate->copy()->subYear()->endOfYear()->timezone($timezone)->utc();

        $analysisDateStr = $targetDate->format('Y-m-d');
        $periodRange = "Năm " . $targetDate->format('Y');

        // 1. Tổng chi tiêu năm nay
        $actualAmount = (float) DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$currentYearStart, $currentYearEnd])
            ->whereNull('deleted_at')
            ->sum('amount_in_user_currency');

        // 2. Tổng chi tiêu năm ngoái
        $baselineAmount = (float) DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$prevYearStart, $prevYearEnd])
            ->whereNull('deleted_at')
            ->sum('amount_in_user_currency');

        $diffAmount = $actualAmount - $baselineAmount;
        
        if ($baselineAmount > 0) {
            $percentChange = round(($diffAmount / $baselineAmount) * 100, 2);
        } else {
            $percentChange = $actualAmount > 0 ? 100.00 : 0.00;
        }

        if ($percentChange > 5) {
            $status = 'overspending';
        } elseif ($percentChange < -5) {
            $status = 'saving';
        } else {
            $status = 'normal';
        }

        // Lấy chi tiêu theo từng tháng trong năm để phân tích xu hướng vĩ mô
        $monthlySpending = DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$currentYearStart, $currentYearEnd])
            ->whereNull('deleted_at')
            ->select(DB::raw("EXTRACT(MONTH FROM transaction_date) as month"), DB::raw('SUM(amount_in_user_currency) as total_amount'))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $prompt = "Tổng chi tiêu năm nay ({$periodRange}): " . number_format($actualAmount, 0) . " VND.\n";
        $prompt .= "Tổng chi tiêu năm ngoái: " . number_format($baselineAmount, 0) . " VND.\n";
        $prompt .= "Độ lệch chi tiêu Year-over-Year (YoY): " . ($percentChange >= 0 ? '+' : '') . $percentChange . "%.\n";
        $prompt .= "Chi tiêu phân bổ theo các tháng trong năm nay:\n";
        foreach ($monthlySpending as $ms) {
            $prompt .= "- Tháng " . intval($ms->month) . ": " . number_format($ms->total_amount, 0) . " VND\n";
        }

        $systemInstruction = "Bạn là cố vấn tài chính vĩ mô cá nhân chuyên nghiệp. Hãy viết báo cáo phân tích tài chính năm cho người dùng bằng Tiếng Việt gồm 2 phần:\n"
            . "1. Đánh giá tổng quan tài chính cả năm: Nhận diện xu hướng thu chi vĩ mô qua các tháng và so sánh với năm ngoái.\n"
            . "2. Mục tiêu và khuyến nghị cho năm tới: Đề xuất mục tiêu tích lũy cụ thể và định hướng phân bổ dòng tiền bền vững.\n"
            . "Yêu cầu: Viết súc tích trong 4-5 câu, mang tính định hướng chiến lược cao.";

        $aiInsight = $this->callGemini($prompt, $systemInstruction);

        if ($aiInsight && !str_starts_with($aiInsight, 'Lỗi')) {
            $this->saveAnalysis($userId, 'yearly', $analysisDateStr, $periodRange, $baselineAmount, $actualAmount, $diffAmount, $percentChange, $status, $aiInsight);
        } else {
            Log::warning("Bỏ qua lưu phân tích yearly cho user {$userId} do lỗi AI: {$aiInsight}");
        }
    }

    /**
     * Ghi nhận phân tích vào database và bảo toàn is_read
     */
    protected function saveAnalysis(string $userId, string $type, string $analysisDate, string $periodRange, float $baselineAmount, float $actualAmount, float $diffAmount, float $percentChange, string $status, string $aiInsight)
    {
        $existing = DB::table('ai_habit_analyses')
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('analysis_date', $analysisDate)
            ->first();

        $id = $existing ? $existing->id : (string) Str::uuid();
        $isRead = $existing ? (bool) $existing->is_read : false;

        DB::table('ai_habit_analyses')->updateOrInsert(
            [
                'user_id' => $userId,
                'type' => $type,
                'analysis_date' => $analysisDate
            ],
            [
                'id' => $id,
                'period_range' => $periodRange,
                'baseline_amount' => $baselineAmount,
                'actual_amount' => $actualAmount,
                'diff_amount' => $diffAmount,
                'percent_change' => $percentChange,
                'status' => $status,
                'ai_insight' => $aiInsight,
                // PDO với ATTR_EMULATE_PREPARES convert bool -> 0/1 (integer)
                // PostgreSQL strict boolean không chấp nhận, dùng DB::raw để bypass
                'is_read' => DB::raw($isRead ? 'true' : 'false'),
                'created_at' => $existing ? $existing->created_at : now(),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Gọi Gemini API
     */
    protected function callGemini(string $prompt, string $systemInstruction): string
    {
        $apiKey = env("GEMINI_API_KEY");
        if (!$apiKey) {
            return "Chưa cấu hình GEMINI_API_KEY.";
        }
        $model = env('GEMINI_MODEL', 'gemini-2.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'system_instruction' => [
                'parts' => [['text' => $systemInstruction]]
            ],
            'generationConfig' => [
                'temperature' => 0.7
            ]
        ];

        $maxRetries = 3;
        $retryDelay = 1000; // ms

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::timeout(30)->post($url, $payload);
                if ($response->successful()) {
                    $result = $response->json();
                    return $result['candidates'][0]['content']['parts'][0]['text'] ?? 'Không có phản hồi từ AI.';
                }
                
                $status = $response->status();
                if ($status === 429 || $status >= 500) {
                    if ($attempt < $maxRetries) {
                        Log::warning("Gemini API lỗi $status (Lần thử $attempt/$maxRetries). Đang chờ thử lại sau " . ($retryDelay / 1000) . "s...");
                        usleep($retryDelay * 1000);
                        $retryDelay *= 2; // exponential backoff
                        continue;
                    }
                }
                return 'Lỗi kết nối API: ' . $status;
            } catch (\Throwable $e) {
                Log::warning("Lỗi kết nối Gemini (Lần thử $attempt/$maxRetries): " . $e->getMessage());
                if ($attempt < $maxRetries) {
                    usleep($retryDelay * 1000);
                    $retryDelay *= 2;
                    continue;
                }
                return 'Lỗi khi gọi AI: ' . $e->getMessage();
            }
        }
        return 'Lỗi kết nối AI sau nhiều lần thử lại.';
    }
}
