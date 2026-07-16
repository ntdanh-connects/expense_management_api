<?php

namespace App\Notifications;

use App\Models\Wallet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MinimumBalanceWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $wallet;
    protected $currentBalance;

    public function __construct(Wallet $wallet, float $currentBalance)
    {
        $this->wallet = $wallet;
        $this->currentBalance = $currentBalance;
        $this->afterCommit = true;
    }

    /**
     * Get the notification's delivery channels.
     */
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

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $walletName = $this->wallet->name;
        $minBalance = number_format($this->wallet->minimum_balance, 2) . ' ' . $this->wallet->currency_code;
        $formattedBalance = number_format($this->currentBalance, 2) . ' ' . $this->wallet->currency_code;
        
        $subject = "⚠️ Cảnh báo số dư ví tối thiểu - {$walletName}";

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Chào ' . ($notifiable->profile->full_name ?? 'bạn') . '!')
            ->line("Số dư trong ví **{$walletName}** của bạn đã giảm xuống dưới mức tối thiểu đặt trước!")
            ->line("• **Hạn mức đặt ra:** {$minBalance}")
            ->line("• **Số dư hiện tại:** {$formattedBalance}")
            ->line('Vui lòng bổ sung số dư hoặc điều chỉnh hạn mức cảnh báo trong ứng dụng.');
    }

    /**
     * Get the array representation of the notification for database channel.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'wallet_id' => $this->wallet->id,
            'wallet_name' => $this->wallet->name,
            'minimum_balance' => $this->wallet->minimum_balance,
            'current_balance' => $this->currentBalance,
            'title' => 'Cảnh báo số dư ví tối thiểu',
            'message' => "Số dư ví '{$this->wallet->name}' ({$this->currentBalance} {$this->wallet->currency_code}) đã giảm xuống dưới mức tối thiểu đặt ra ({$this->wallet->minimum_balance} {$this->wallet->currency_code})."
        ];
    }

    /**
     * Get the FCM representation of the notification.
     */
    public function toFcm(object $notifiable): array
    {
        $title = '⚠️ Cảnh báo số dư ví tối thiểu';
        $body = "Số dư ví '{$this->wallet->name}' đã xuống dưới mức tối thiểu đặt ra.";

        return [
            'title' => $title,
            'body' => $body,
            'data' => $this->toArray($notifiable)
        ];
    }
}
