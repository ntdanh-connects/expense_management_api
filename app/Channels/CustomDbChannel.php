<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $notifiable->user_id ?? $notifiable->getKey(),
            'type' => get_class($notification),
            'title' => $title,
            'content' => $content,
            'metadata' => json_encode($data),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}
