<?php

namespace App\Notifications;

use App\Models\RecurringRule;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Channels\CustomDbChannel;

class RecurringTransactionExecutedNotification extends Notification
{
    use Queueable;

    protected $rule;
    protected $transaction;
    protected $status;
    protected $errorMessage;

    public function __construct(RecurringRule $rule, ?Transaction $transaction, string $status, ?string $errorMessage = null)
    {
        $this->rule = $rule;
        $this->transaction = $transaction;
        $this->status = $status;
        $this->errorMessage = $errorMessage;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', CustomDbChannel::class, \App\Channels\FcmChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $currency = $notifiable->preference->currency ?? 'VND';
        $formattedAmount = number_format($this->rule->amount, 2) . ' ' . $currency;
        $walletName = $this->rule->wallet ? $this->rule->wallet->name : 'Ví của bạn';

        $mailMessage = new MailMessage();

        if ($this->status === 'success') {
            $mailMessage->subject("✅ Giao dịch định kỳ đã thực hiện - {$this->rule->title}")
                ->greeting('Chào ' . ($notifiable->profile->full_name ?? 'bạn') . '!')
                ->line("Giao dịch định kỳ **\"{$this->rule->title}\"** đã tự động được tạo thành công.")
                ->line("• **Ví thực hiện:** {$walletName}")
                ->line("• **Loại giao dịch:** " . ($this->rule->type === 'expense' ? 'Chi tiêu' : 'Thu nhập'))
                ->line("• **Số tiền:** {$formattedAmount}")
                ->line("Cảm ơn bạn đã sử dụng dịch vụ của chúng tôi!");
        } else {
            $mailMessage->subject("⚠️ Thất bại thực thi giao dịch định kỳ - {$this->rule->title}")
                ->greeting('Chào ' . ($notifiable->profile->full_name ?? 'bạn') . '!')
                ->line("Hệ thống đã cố gắng thực thi giao dịch định kỳ **\"{$this->rule->title}\"** nhưng thất bại.")
                ->line("• **Ví thực hiện:** {$walletName}")
                ->line("• **Lý do thất bại:** " . ($this->errorMessage ?? 'Lỗi không xác định.'))
                ->line("Vui lòng kiểm tra lại số dư ví hoặc cấu hình để tránh bị gián đoạn các chu kỳ tiếp theo.");
        }

        return $mailMessage;
    }

    /**
     * Get the array representation of the notification for CustomDbChannel.
     */
    public function toArray(object $notifiable): array
    {
        $currency = $notifiable->preference->currency ?? 'VND';
        $formattedAmount = number_format($this->rule->amount, 2) . ' ' . $currency;

        if ($this->status === 'success') {
            return [
                'recurring_rule_id' => $this->rule->id,
                'transaction_id' => $this->transaction ? $this->transaction->id : null,
                'status' => 'success',
                'title' => 'Giao dịch định kỳ thành công',
                'message' => "Giao dịch định kỳ \"{$this->rule->title}\" số tiền {$formattedAmount} đã được tự động thực hiện thành công.",
                'amount' => $this->rule->amount,
            ];
        } else {
            return [
                'recurring_rule_id' => $this->rule->id,
                'transaction_id' => null,
                'status' => 'failed',
                'title' => 'Lỗi giao dịch định kỳ',
                'message' => "Giao dịch định kỳ \"{$this->rule->title}\" thực thi thất bại: " . ($this->errorMessage ?? 'Lỗi không xác định.'),
                'amount' => $this->rule->amount,
                'error_message' => $this->errorMessage,
            ];
        }
    }

    /**
     * Get the FCM representation of the notification.
     */
    public function toFcm(object $notifiable): array
    {
        $title = $this->status === 'success' ? '✅ Giao dịch định kỳ thành công' : '⚠️ Lỗi giao dịch định kỳ';
        $body = $this->status === 'success'
            ? "Giao dịch định kỳ \"{$this->rule->title}\" đã được tự động thực hiện thành công."
            : "Giao dịch định kỳ \"{$this->rule->title}\" thực thi thất bại: " . ($this->errorMessage ?? 'Lỗi không xác định.');

        return [
            'title' => $title,
            'body' => $body,
            'data' => $this->toArray($notifiable)
        ];
    }
}

