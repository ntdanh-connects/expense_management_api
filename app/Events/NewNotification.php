<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event phát sóng realtime khi có thông báo mới (Budget alert, Export/Import xong, v.v.)
 * Frontend lắng nghe kênh private 'user.{userId}' để nhận event này.
 */
class NewNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $userId;
    public array $notification;

    /**
     * Create a new event instance.
     */
    public function __construct(string $userId, array $notification)
    {
        $this->userId = $userId;
        $this->notification = $notification;
    }

    /**
     * Kênh private mà event sẽ được phát sóng tới.
     * Chỉ user sở hữu userId mới nghe được (đã xác thực trong routes/channels.php).
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    /**
     * Tên event mà Frontend sẽ lắng nghe.
     * Ví dụ: Echo.private('user.xxx').listen('NewNotification', callback)
     */
    public function broadcastAs(): string
    {
        return 'NewNotification';
    }

    /**
     * Dữ liệu gửi kèm event (payload).
     */
    public function broadcastWith(): array
    {
        return $this->notification;
    }
}
