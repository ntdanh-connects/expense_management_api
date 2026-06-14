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
            $path = $this->credentialsPath;
            if (!$path) {
                Log::warning('FCM: Đường dẫn Firebase credentials chưa được cấu hình.');
                return null;
            }

            // Giải quyết đường dẫn tương đối hoặc tuyệt đối
            if (!file_exists($path)) {
                if (file_exists(base_path($path))) {
                    $path = base_path($path);
                } else {
                    Log::warning("FCM: Không tìm thấy file credentials tại: {$path}");
                    return null;
                }
            }

            $client = new GoogleClient();
            $client->setAuthConfig($path);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $client->refreshTokenWithAssertion();
            
            $accessTokenObj = $client->getAccessToken();
            return $accessTokenObj['access_token'] ?? null;
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
                    ->post($url, $payload);

                if ($response->successful()) {
                    $successCount++;
                } else {
                    Log::error('FCM: Lỗi gửi thông báo đến token ' . substr($token, 0, 10) . '... Phản hồi: ' . $response->body());
                }
            } catch (\Exception $e) {
                Log::error('FCM: Exception xảy ra khi gửi thông báo: ' . $e->getMessage());
            }
        }

        return $successCount > 0;
    }
}
