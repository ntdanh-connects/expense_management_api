<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Kênh private dành cho từng user. Chỉ user sở hữu user_id mới được phép
| subscribe vào kênh notifications và budget-alerts của chính họ.
|
*/

Broadcast::channel('user.{userId}', function ($user, $userId) {
    // $user ở đây là đối tượng User được resolve từ route auth broadcasting
    return $user->user_id === $userId;
});
