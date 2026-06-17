<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $user;
    protected $token;

    public function __construct($user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
        $this->afterCommit = true;
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
        $resetUrl = route('password.reset', [
            'token' => $this->token,
            'email' => $this->user->email
        ]);

        return (new MailMessage)
            ->subject('Đặt lại mật khẩu tài khoản App Quản lý chi tiêu')
            ->greeting('Chào bạn' . ($this->user->profile->full_name ?? '') . '!')
            ->line('Chúng tôi đã nhận được yêu cầu đặt lại mật khẩu cho tài khoản App Quản lý chi tiêu của bạn.')
            ->line('Vui lòng nhấn vào nút bên dưới để tiến hành thiết lập mật khẩu mới:')
            ->action('Đặt Lại Mật Khẩu', $resetUrl)
            ->line('Đường link đặt lại mật khẩu này sẽ hết hạn sau 60 phút.')
            ->line('Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email này. Mật khẩu hiện tại của bạn vẫn được giữ an toàn tuyệt đối.');
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
