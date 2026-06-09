<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Channels\CustomDbChannel;

class ExportCompletedNotification extends Notification
{
    use Queueable;

    protected $exportId;
    protected $isSuccess;
    protected $fileUrl;
    protected $errorMessage;

    public function __construct(string $exportId, bool $isSuccess, ?string $fileUrl = null, ?string $errorMessage = null)
    {
        $this->exportId = $exportId;
        $this->isSuccess = $isSuccess;
        $this->fileUrl = $fileUrl;
        $this->errorMessage = $errorMessage;
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
        $mailMessage = new MailMessage();

        if ($this->isSuccess) {
            $mailMessage->subject("📊 File xuất giao dịch của bạn đã sẵn sàng")
                ->greeting('Chào ' . ($notifiable->profile->full_name ?? 'bạn') . '!')
                ->line("Yêu cầu xuất dữ liệu giao dịch của bạn đã được xử lý thành công.")
                ->action('Tải File Giao Dịch', $this->fileUrl)
                ->line('Đường dẫn tải file sẽ hết hạn tùy vào cài đặt bảo mật của server storage.');
        } else {
            $mailMessage->subject("⚠️ Xuất giao dịch thất bại")
                ->greeting('Chào ' . ($notifiable->profile->full_name ?? 'bạn') . '!')
                ->line("Yêu cầu xuất dữ liệu giao dịch của bạn đã gặp lỗi.")
                ->line("• **Chi tiết lỗi:** " . ($this->errorMessage ?? 'Lỗi không xác định.'));
        }

        return $mailMessage;
    }

    /**
     * Get the array representation of the notification for CustomDbChannel.
     */
    public function toArray(object $notifiable): array
    {
        if ($this->isSuccess) {
            return [
                'export_id' => $this->exportId,
                'status' => 'success',
                'file_url' => $this->fileUrl,
                'title' => 'Xuất giao dịch thành công',
                'message' => 'Yêu cầu xuất danh sách giao dịch của bạn đã hoàn thành. Nhấn để tải về.'
            ];
        } else {
            return [
                'export_id' => $this->exportId,
                'status' => 'failed',
                'title' => 'Xuất giao dịch thất bại',
                'message' => 'Lỗi xuất file giao dịch: ' . ($this->errorMessage ?? 'Lỗi không xác định.')
            ];
        }
    }
}
