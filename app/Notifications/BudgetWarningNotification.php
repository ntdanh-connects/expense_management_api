<?php

namespace App\Notifications;

use App\Models\Budget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BudgetWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $budget;
    protected $thresholdPercent;
    protected $usedAmount;

    public function __construct(Budget $budget, int $thresholdPercent, float $usedAmount)
    {
        $this->budget = $budget;
        $this->thresholdPercent = $thresholdPercent;
        $this->usedAmount = $usedAmount;
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
        $categoryName = $this->budget->category ? $this->budget->category->name : 'Tổng chi tiêu';
        $limitAmount = number_format($this->budget->limit_amount, 2) . ' ' . $notifiable->preference->currency;
        $formattedUsedAmount = number_format($this->usedAmount, 2) . ' ' . $notifiable->preference->currency;
        
        $subject = $this->thresholdPercent === 100 
            ? "⚠️ Vượt hạn mức ngân sách - {$categoryName}"
            : "⚡ Cảnh báo ngân sách đạt {$this->thresholdPercent}% - {$categoryName}";

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Chào ' . ($notifiable->profile->full_name ?? 'bạn') . '!');

        if ($this->thresholdPercent === 100) {
            $message->line("Hạn mức ngân sách dành cho **{$categoryName}** của bạn trong tháng {$this->budget->month}/{$this->budget->year} đã bị **vượt quá**!")
                ->line("• **Hạn mức đặt ra:** {$limitAmount}")
                ->line("• **Số tiền đã tiêu:** {$formattedUsedAmount}")
                ->line('Vui lòng kiểm tra lại các khoản chi tiêu và điều chỉnh ngân sách cho phù hợp.');
        } else {
            $message->line("Chi tiêu dành cho **{$categoryName}** của bạn trong tháng {$this->budget->month}/{$this->budget->year} đã đạt **{$this->thresholdPercent}%** hạn mức!")
                ->line("• **Hạn mức đặt ra:** {$limitAmount}")
                ->line("• **Số tiền đã tiêu:** {$formattedUsedAmount}")
                ->line('Hãy cân nhắc chi tiêu hợp lý trong phần còn lại của tháng.');
        }

        return $message;
    }

    /**
     * Get the array representation of the notification for database channel.
     */
    public function toArray(object $notifiable): array
    {
        $categoryName = $this->budget->category ? $this->budget->category->name : 'Tổng chi tiêu';
        return [
            'budget_id' => $this->budget->id,
            'category_name' => $categoryName,
            'month' => $this->budget->month,
            'year' => $this->budget->year,
            'limit_amount' => $this->budget->limit_amount,
            'used_amount' => $this->usedAmount,
            'threshold_percent' => $this->thresholdPercent,
            'title' => $this->thresholdPercent === 100 ? 'Vượt quá hạn mức ngân sách' : 'Cảnh báo ngân sách',
            'message' => $this->thresholdPercent === 100 
                ? "Ngân sách cho {$categoryName} đã vượt quá hạn mức!"
                : "Hạn mức ngân sách cho {$categoryName} đã đạt {$this->thresholdPercent}%."
        ];
    }

    /**
     * Get the FCM representation of the notification.
     */
    public function toFcm(object $notifiable): array
    {
        $categoryName = $this->budget->category ? $this->budget->category->name : 'Tổng chi tiêu';
        $title = $this->thresholdPercent === 100 ? '⚠️ Vượt hạn mức ngân sách' : '⚡ Cảnh báo ngân sách';
        $body = $this->thresholdPercent === 100 
            ? "Ngân sách cho {$categoryName} đã vượt quá 100% hạn mức!"
            : "Hạn mức ngân sách cho {$categoryName} đã đạt {$this->thresholdPercent}%.";

        return [
            'title' => $title,
            'body' => $body,
            'data' => $this->toArray($notifiable)
        ];
    }
}

