<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Channels\CustomDbChannel;

class ImportCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $importId;
    protected $successRows;
    protected $failedRows;
    protected $totalRows;
    protected $errorFileUrl;

    public function __construct(string $importId, int $successRows, int $failedRows, int $totalRows, ?string $errorFileUrl = null)
    {
        $this->importId = $importId;
        $this->successRows = $successRows;
        $this->failedRows = $failedRows;
        $this->totalRows = $totalRows;
        $this->errorFileUrl = $errorFileUrl;
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
        $mailMessage = (new MailMessage())
            ->subject("📥 Tiến trình nhập giao dịch đã hoàn tất")
            ->greeting('Chào ' . ($notifiable->profile->full_name ?? 'bạn') . '!')
            ->line("Tiến trình nhập dữ liệu giao dịch từ file CSV của bạn đã hoàn thành.")
            ->line("• **Tổng số dòng xử lý:** {$this->totalRows}")
            ->line("• **Thành công:** {$this->successRows} dòng")
            ->line("• **Thất bại:** {$this->failedRows} dòng");

        if ($this->failedRows > 0 && $this->errorFileUrl) {
            $mailMessage->line("Một số dòng dữ liệu bị lỗi và không thể nhập vào hệ thống. Bạn có thể tải file danh sách dòng lỗi bên dưới để kiểm tra lại:")
                ->action('Tải File Báo Lỗi', $this->errorFileUrl);
        }

        return $mailMessage;
    }

    /**
     * Get the array representation of the notification for CustomDbChannel.
     */
    public function toArray(object $notifiable): array
    {
        $message = "Nhập dữ liệu thành công {$this->successRows}/{$this->totalRows} giao dịch.";
        if ($this->failedRows > 0) {
            $message .= " Có {$this->failedRows} dòng lỗi.";
        }

        return [
            'import_id' => $this->importId,
            'success_rows' => $this->successRows,
            'failed_rows' => $this->failedRows,
            'total_rows' => $this->totalRows,
            'error_file_url' => $this->errorFileUrl,
            'status' => $this->failedRows === 0 ? 'success' : ($this->successRows === 0 ? 'failed' : 'warning'),
            'title' => 'Nhập giao dịch hoàn tất',
            'message' => $message
        ];
    }

    /**
     * Get the FCM representation of the notification.
     */
    public function toFcm(object $notifiable): array
    {
        $title = '📥 Nhập giao dịch hoàn tất';
        $body = "Nhập dữ liệu thành công {$this->successRows}/{$this->totalRows} giao dịch.";
        if ($this->failedRows > 0) {
            $body .= " Có {$this->failedRows} dòng lỗi.";
        }

        return [
            'title' => $title,
            'body' => $body,
            'data' => $this->toArray($notifiable)
        ];
    }
}
