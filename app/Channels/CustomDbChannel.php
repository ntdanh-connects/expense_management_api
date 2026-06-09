<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Events\NewNotification;

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
        $data = method_exists($notification, 'toDatabase')
            ? $notification->toDatabase($notifiable)
            : $notification->toArray($notifiable);

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
        event(new NewNotification($userId, [
            'id' => $notificationId,
            'title' => $title,
            'content' => $content,
            'metadata' => $data,
            'read_at' => null,
            'created_at' => $now->toISOString(),
        ]));
    }
}
