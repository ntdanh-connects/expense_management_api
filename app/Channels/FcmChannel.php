<?php

namespace App\Channels;

use App\Services\FcmService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class FcmChannel
{
    protected $fcmService;

    public function __construct(FcmService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    public function send($notifiable, Notification $notification)
    {
        // 1. Kiểm tra cấu hình preferences của user có cho phép nhận push notification không qua relation
        $pushEnabled = $notifiable->notificationPreference->push_enabled ?? true;

        if (!$pushEnabled) {
            return;
        }

        // 2. Lấy danh sách device token của user
        $tokens = DB::table('user_device_tokens')
            ->where('user_id', $notifiable->user_id)
            ->pluck('device_token')
            ->toArray();

        if (empty($tokens)) {
            return;
        }

        // 3. Format dữ liệu gửi đi (phương thức toFcm trong Notification class)
        if (!method_exists($notification, 'toFcm')) {
            return;
        }

        $payload = $notification->toFcm($notifiable);

        if (empty($payload)) {
            return;
        }

        // 4. Gửi qua FCM Service
        $this->fcmService->sendNotification(
            $tokens,
            $payload['title'] ?? '',
            $payload['body'] ?? '',
            $payload['data'] ?? []
        );
    }
}
