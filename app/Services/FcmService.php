<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected $projectId;
    protected $credentialsPath;

    public function __construct()
    {
        $this->projectId = config('services.firebase.project_id');
        $this->credentialsPath = config('services.firebase.credentials');
    }

    /**
     * Lấy OAuth2 access token thông qua Google Client sử dụng Service Account JSON
     */
    protected function getAccessToken(): ?string
    {
        try {
            $credentials = $this->credentialsPath;
            if (!$credentials) {
                Log::warning('FCM: Firebase credentials chưa được cấu hình.');
                return null;
            }

            $jsonData = null;

            // 1. Kiểm tra xem credentials có phải là chuỗi JSON trực tiếp không
            $decoded = json_decode($credentials, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_string($decoded)) {
                    $decoded = json_decode($decoded, true);
                }
                if (is_array($decoded)) {
                    $jsonData = $decoded;
                }
            }
            
            if (!$jsonData) {
                // 2. Nếu không phải JSON, coi đó là đường dẫn file
                $path = $credentials;
                if (!file_exists($path)) {
                    if (file_exists(base_path($path))) {
                        $path = base_path($path);
                    } else {
                        Log::warning("FCM: Không tìm thấy file credentials tại: {$path}");
                        return null;
                    }
                }

                if (!is_readable($path)) {
                    Log::warning("FCM: File credentials tại {$path} tồn tại nhưng không có quyền đọc (Permission denied).");
                    return null;
                }

                $fileContent = file_get_contents($path);
                $jsonData = json_decode($fileContent, true);
            }

            if (!is_array($jsonData)) {
                Log::warning("FCM: Định dạng credentials không hợp lệ (không phải JSON hợp lệ).");
                return null;
            }

            // Tự động sửa lỗi private_key chứa kí tự \n viết thường thay vì xuống dòng thực tế (do bị escape)
            if (isset($jsonData['private_key'])) {
                $jsonData['private_key'] = str_replace('\n', "\n", $jsonData['private_key']);
            }

            // 3. Lấy Access Token (dùng Google\Client nếu có, hoặc dùng pure PHP OpenSSL JWT nếu chưa có composer vendor)
            if (class_exists(\Google\Client::class)) {
                $client = new \Google\Client();
                $client->setAuthConfig($jsonData);
                $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
                $client->refreshTokenWithAssertion();
                $accessTokenObj = $client->getAccessToken();
                return $accessTokenObj['access_token'] ?? null;
            }

            // Pure PHP Google Service Account OAuth2 JWT Assertion
            $clientEmail = $jsonData['client_email'] ?? null;
            $privateKey = $jsonData['private_key'] ?? null;

            if (!$clientEmail || !$privateKey) {
                Log::warning('FCM: Thiếu client_email hoặc private_key trong Firebase credentials JSON.');
                return null;
            }

            $now = time();
            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $payload = json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]);

            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
            $signatureInput = $base64UrlHeader . '.' . $base64UrlPayload;

            $signature = '';
            if (!openssl_sign($signatureInput, $signature, $privateKey, 'SHA256')) {
                Log::error('FCM: Lỗi ký chữ ký OpenSSL cho JWT Google Token.');
                return null;
            }

            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
            $jwt = $signatureInput . '.' . $base64UrlSignature;

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful()) {
                return $response->json('access_token');
            }

            Log::error('FCM: Lỗi yêu cầu OAuth2 Token từ Google API: ' . $response->body());
            return null;
        } catch (\Exception $e) {
            Log::error('FCM: Lỗi khi lấy Access Token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Gửi thông báo đến danh sách device tokens
     */
    public function sendNotification(array $deviceTokens, string $title, string $body, array $data = []): bool
    {
        if (empty($deviceTokens)) {
            return false;
        }

        if (!$this->projectId) {
            Log::warning('FCM: Project ID chưa được cấu hình.');
            return false;
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            Log::warning('FCM: Không thể lấy access token để xác thực.');
            return false;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $successCount = 0;

        foreach ($deviceTokens as $token) {
            try {
                // Định dạng payload gửi lên FCM HTTP v1 API
                $payload = [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'channel_id' => 'expense_management_channel_v4',
                                'sound' => 'default',
                            ],
                        ],
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10',
                            ],
                            'payload' => [
                                'aps' => [
                                    'alert' => [
                                        'title' => $title,
                                        'body' => $body,
                                    ],
                                    'sound' => 'default',
                                    'badge' => 1,
                                ],
                            ],
                        ],
                    ]
                ];

                if (!empty($data)) {
                    // FCM HTTP v1 yêu cầu toàn bộ key và value của data phải là chuỗi (string)
                    $stringData = [];
                    foreach ($data as $key => $value) {
                        $stringData[(string)$key] = is_array($value) ? json_encode($value) : (string)$value;
                    }
                    $payload['message']['data'] = $stringData;
                }

                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(5)
                    ->post($url, $payload);

                if ($response->successful()) {
                    $successCount++;
                } else {
                    Log::error('FCM: Lỗi gửi thông báo đến token ' . substr($token, 0, 10) . '... Phản hồi: ' . $response->body());

                    // Tự động xoá token hết hạn/unregistered khỏi database để tối ưu hiệu năng
                    $resData = $response->json();
                    $errorCode = $resData['error']['details'][0]['errorCode'] ?? null;
                    if ($response->status() === 404 || $errorCode === 'UNREGISTERED') {
                        \Illuminate\Support\Facades\DB::table('user_device_tokens')->where('device_token', $token)->delete();
                        Log::info('FCM: Đã xoá token hết hạn/unregistered khỏi DB: ' . substr($token, 0, 15) . '...');
                    }
                }
            } catch (\Exception $e) {
                Log::error('FCM: Exception xảy ra khi gửi thông báo: ' . $e->getMessage());
            }
        }

        return $successCount > 0;
    }
}
