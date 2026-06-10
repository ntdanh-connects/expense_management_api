<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Channels\CustomDbChannel;

class DailyReminderNotification extends Notification
{
    use Queueable;

    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', CustomDbChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $fullName = $notifiable->profile->full_name ?? 'bạn';

        return (new MailMessage)
            ->subject('⏰ Nhắc nhở: Ghi chép chi tiêu cuối ngày')
            ->greeting("Chào {$fullName}!")
            ->line('Hôm nay bạn chưa có giao dịch nào được ghi chép vào hệ thống.')
            ->line('Hãy dành ra 1 phút để ghi chép lại các khoản thu chi hôm nay nhé, việc này sẽ giúp bạn quản lý tài chính tốt hơn!')
            ->action('Ghi chép ngay', url('/'))
            ->line('Chúc bạn một buổi tối vui vẻ!');
    }

    /**
     * Get the array representation of the notification for CustomDbChannel.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Nhắc nhở ghi chép chi tiêu',
            'message' => 'Hôm nay bạn chưa ghi chép chi tiêu nào. Hãy dành ít phút để cập nhật nhé!',
            'type' => 'daily_reminder'
        ];
    }
}
