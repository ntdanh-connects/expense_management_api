<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocalizationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $locale = 'vi'; // Mặc định là Tiếng Việt

        // 1. Kiểm tra Token bảo mật để lấy cấu hình ngôn ngữ của user đã đăng nhập
        $authHeader = $request->header('Authorization');
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            $session = DB::table('user_sessions')
                ->where('access_token_hash', hash('sha256', $token))
                ->where('access_token_expired_at', '>', now())
                ->whereNull('revoked_at')
                ->first();

            if ($session) {
                $userLocale = DB::table('user_preferences')
                    ->where('user_id', $session->user_id)
                    ->value('language');
                if ($userLocale) {
                    $locale = $userLocale;
                }
            }
        } else {
            // 2. Nếu là API công khai (chưa đăng nhập), kiểm tra qua tham số lang hoặc header Accept-Language
            $langParam = $request->input('lang');
            $acceptLang = $request->header('Accept-Language');

            if ($langParam) {
                $locale = $langParam;
            } elseif ($acceptLang) {
                // Tách lấy ngôn ngữ đầu tiên hoặc kiểm tra nếu chứa 'en'
                $locale = str_starts_with(strtolower($acceptLang), 'en') ? 'en' : 'vi';
            }
        }

        // Chuẩn hoá ngôn ngữ về vi hoặc en
        $locale = in_array(strtolower($locale), ['vi', 'en']) ? strtolower($locale) : 'vi';

        // Thiết lập Locale cho Request Context
        app()->setLocale($locale);

        return $next($request);
    }
}
