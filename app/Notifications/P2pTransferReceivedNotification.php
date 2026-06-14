<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Channels\CustomDbChannel;

class P2pTransferReceivedNotification extends Notification
{
    use Queueable;

    protected $senderName;
    protected $amount;
    protected $currency;
    protected $notes;

    public function __construct(string $senderName, float $amount, string $currency, ?string $notes = null)
    {
        $this->senderName = $senderName;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->notes = $notes;
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
        $formattedAmount = number_format($this->amount, 2) . ' ' . $this->currency;
        $subject = "💸 Bạn đã nhận được tiền từ {$this->senderName}";

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Chào ' . ($notifiable->profile->full_name ?? 'bạn') . '!')
            ->line("Bạn vừa nhận được một khoản chuyển tiền từ **{$this->senderName}**.")
            ->line("• **Số tiền:** {$formattedAmount}")
            ->line("• **Lời nhắn:** " . ($this->notes ?? 'Không có lời nhắn.'))
            ->line("Vui lòng mở ứng dụng để kiểm tra số dư ví mới của bạn.");
    }

    /**
     * Get the array representation of the notification for CustomDbChannel.
     */
    public function toArray(object $notifiable): array
    {
        $formattedAmount = number_format($this->amount, 2) . ' ' . $this->currency;
        return [
            'sender_name' => $this->senderName,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'title' => 'Nhận tiền thành công',
            'message' => "Bạn đã nhận được {$formattedAmount} từ {$this->senderName}.",
        ];
    }

    /**
     * Get the FCM representation of the notification.
     */
    public function toFcm(object $notifiable): array
    {
        $formattedAmount = number_format($this->amount, 2) . ' ' . $this->currency;
        $title = "💸 Nhận tiền thành công";
        $body = "Bạn đã nhận được {$formattedAmount} từ {$this->senderName}.";

        return [
            'title' => $title,
            'body' => $body,
            'data' => $this->toArray($notifiable)
        ];
    }
}
