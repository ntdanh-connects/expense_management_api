<?php

namespace App\Notifications;

use App\Models\RecurringRule;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class RecurringUpcomingReminderNotification extends Notification
{
    use Queueable;

    protected $rule;
    protected $daysRemaining; // 2, 1, or 0 (today)

    public function __construct(RecurringRule $rule, int $daysRemaining)
    {
        $this->rule = $rule;
        $this->daysRemaining = $daysRemaining;
    }

    public function via(object $notifiable): array
    {
        $channels = [\App\Channels\CustomDbChannel::class];

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

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->getNotificationTitle();
        $message = $this->getNotificationMessage();

        return (new MailMessage)
            ->subject($title)
            ->greeting('Xin chào ' . ($notifiable->userProfile->full_name ?? 'bạn') . '!')
            ->line($message)
            ->line('Số tiền: ' . number_format((float)$this->rule->amount) . ' VND')
            ->line('Tần suất: ' . $this->rule->frequency)
            ->action('Xem giao dịch định kỳ', url('/'))
            ->line('Cảm ơn bạn đã sử dụng ứng dụng Quản lý Chi tiêu!');
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->getNotificationTitle(),
            'body' => $this->getNotificationMessage(),
            'data' => [
                'type' => 'recurring_upcoming_reminder',
                'rule_id' => (string) $this->rule->id,
                'days_remaining' => (string) $this->daysRemaining,
            ],
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'recurring_upcoming_reminder',
            'title' => $this->getNotificationTitle(),
            'message' => $this->getNotificationMessage(),
            'rule_id' => $this->rule->id,
            'days_remaining' => $this->daysRemaining,
            'amount' => (float) $this->rule->amount,
        ];
    }

    protected function getNotificationTitle(): string
    {
        if ($this->daysRemaining === 0) {
            return "Hôm nay là ngày giao dịch định kỳ";
        }
        return "Giao dịch định kỳ sắp tới hạn";
    }

    protected function getNotificationMessage(): string
    {
        $formattedAmount = number_format((float) $this->rule->amount) . ' VND';
        if ($this->daysRemaining === 0) {
            return "Hôm nay là ngày giao dịch định kỳ '{$this->rule->title}' ({$formattedAmount}).";
        }
        return "Giao dịch định kỳ '{$this->rule->title}' ({$formattedAmount}) còn {$this->daysRemaining} ngày nữa tới hạn.";
    }
}
