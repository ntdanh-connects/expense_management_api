<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Channels\CustomDbChannel;

class WeeklySummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $startDate;
    protected $endDate;
    protected $income;
    protected $expense;
    protected $categoriesBreakdown;

    public function __construct(string $startDate, string $endDate, float $income, float $expense, array $categoriesBreakdown)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->income = $income;
        $this->expense = $expense;
        $this->categoriesBreakdown = $categoriesBreakdown;
        $this->afterCommit = true;
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
        $fullName = $notifiable->profile->full_name ?? 'bạn';
        $currency = $notifiable->preference->currency ?? 'VND';
        
        $formattedIncome = number_format($this->income, 2) . ' ' . $currency;
        $formattedExpense = number_format($this->expense, 2) . ' ' . $currency;
        $netSavings = $this->income - $this->expense;
        $formattedNet = number_format($netSavings, 2) . ' ' . $currency;

        $mailMessage = (new MailMessage)
            ->subject("📊 Báo cáo tóm tắt chi tiêu tuần qua ({$this->startDate} - {$this->endDate})")
            ->greeting("Chào {$fullName}!")
            ->line("Dưới đây là tóm tắt tài chính của bạn trong tuần vừa qua:")
            ->line("• **Tổng thu nhập:** {$formattedIncome}")
            ->line("• **Tổng chi tiêu:** {$formattedExpense}")
            ->line("• **Tích lũy ròng:** " . ($netSavings >= 0 ? " +{$formattedNet}" : " {$formattedNet}"));

        if (!empty($this->categoriesBreakdown)) {
            $mailMessage->line("\n**Top 3 danh mục chi tiêu nhiều nhất:**");
            $count = 1;
            foreach ($this->categoriesBreakdown as $cat) {
                if ($count > 3) break;
                $formattedCatAmount = number_format($cat['amount'], 2) . ' ' . $currency;
                $mailMessage->line("{$count}. **{$cat['category_name']}:** {$formattedCatAmount} ({$cat['percentage']}%)");
                $count++;
            }
        }

        return $mailMessage
            ->line("\nHãy tiếp tục duy trì thói quen ghi chép chi tiêu để quản lý tài chính tốt hơn nhé!")
            ->action('Xem chi tiết báo cáo', url('/reports'));
    }

    /**
     * Get the array representation of the notification for CustomDbChannel.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Báo cáo tuần qua',
            'message' => 'Báo cáo tóm tắt tài chính tuần qua của bạn đã sẵn sàng.',
            'type' => 'weekly_summary',
            'income' => $this->income,
            'expense' => $this->expense,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate
        ];
    }
}
