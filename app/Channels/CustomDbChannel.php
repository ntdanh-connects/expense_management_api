<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Events\NewNotification;
use Illuminate\Support\Facades\Log;

class CustomDbChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification): void
    {
        if (method_exists($notification, 'toDatabase')) {
            $data = $notification->toDatabase($notifiable);
        } elseif (method_exists($notification, 'toArray')) {
            $data = $notification->toArray($notifiable);
        } else {
            $data = [];
        }

        $data = is_array($data) ? $data : [];

        $title = $data['title'] ?? null;
        $content = $data['message'] ?? $data['content'] ?? null;

        // Xoá title và message/content khỏi phần metadata để tránh lặp dữ liệu
        unset($data['title'], $data['message'], $data['content']);

        // Tạo ID trước để dùng cho cả DB insert lẫn broadcast payload
        $notificationId = (string) Str::uuid7();
        $userId = $notifiable->user_id ?? $notifiable->getKey();
        $now = now();

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'user_id' => $userId,
            'type' => get_class($notification),
            'title' => $title,
            'content' => $content,
            'metadata' => json_encode($data),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Phát sóng realtime qua Pusher — Frontend sẽ nhận được ngay lập tức
        try {
            event(new NewNotification($userId, [
                'id' => $notificationId,
                'title' => $title,
                'content' => $content,
                'metadata' => $data,
                'read_at' => null,
                'created_at' => $now->toISOString(),
            ]));
        } catch (\Throwable $e) {
            Log::warning("Lỗi phát sóng realtime qua Pusher: " . $e->getMessage());
        }
    }
}
