<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    protected $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $verifyURL = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinute(60),
            [
                'id' => $this->user->user_id,
                'hash' => sha1($this->user->email)
            ]
        );
        return (new MailMessage)
            ->subject('Xác thực tài khoản App Quản lý chi tiêu')
            ->greeting('Chào sếp ' . ($this->user->profile->full_name ?? '') . '!')
            ->line('Cảm ơn sếp đã đăng ký tài khoản tại hệ thống của chúng tôi.')
            ->line('Vui lòng nhấn vào nút bên dưới để kích hoạt tài khoản và bắt đầu trải nghiệm.')
            ->action('Xác Thực Tài Khoản', $verifyURL)
            ->line('Đường link này sẽ hết hạn sau 60 phút.')
            ->line('Nếu sếp không đăng ký tài khoản này, vui lòng bỏ qua email này.');
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
