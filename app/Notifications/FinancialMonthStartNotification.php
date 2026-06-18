<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Channels\CustomDbChannel;

class FinancialMonthStartNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $startDay;

    public function __construct(int $startDay)
    {
        $this->startDay = $startDay;
        $this->afterCommit = true;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        $channels = [CustomDbChannel::class];

        $preference = $notifiable->notificationPreference;
        $emailEnabled = $preference ? $preference->email_enabled : true;
        $pushEnabled = $preference ? $preference->push_enabled : true;

        if ($emailEnabled) {
            $channels[] = 'mail';
        }
        if ($pushEnabled) {
            $channels[] = \App\Channels\FcmChannel::class;
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("📅 Bắt đầu tháng tài chính mới")
            ->greeting('Chào ' . ($notifiable->profile->full_name ?? 'bạn') . '!')
            ->line("Hôm nay là ngày bắt đầu chu kỳ tháng tài chính mới của bạn (Ngày {$this->startDay} hàng tháng).")
            ->line("Chúc bạn một tháng mới quản lý chi tiêu hiệu quả và đạt được nhiều mục tiêu tài chính!");
    }

    /**
     * Get the array representation of the notification for database channel.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'start_day' => $this->startDay,
            'title' => 'Bắt đầu tháng tài chính',
            'message' => "Hôm nay bắt đầu chu kỳ tháng tài chính mới (Ngày {$this->startDay} hàng tháng)."
        ];
    }

    /**
     * Get the FCM representation of the notification.
     */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => '📅 Bắt đầu tháng tài chính mới',
            'body' => "Hôm nay bắt đầu chu kỳ tháng tài chính mới (Ngày {$this->startDay} hàng tháng). Chúc bạn chi tiêu thông thái!",
            'data' => $this->toArray($notifiable)
        ];
    }
}
