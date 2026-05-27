<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Override;

class UserRepository extends BaseRepository implements UserRepositoryInterface {

    public function getModel()
    {
        return User::class;
    }

    public function findByEmail(string $email)
    {
        return $this->model->where('email', $email)->first();
    }

    public function findSessionbyToken(string $userId, string $hashedToken)
    {
        return DB::table('user_sessions')
            ->where('user_id',$userId)
            ->where('expired_at','>',now())
            ->where('refresh_token_hash',$hashedToken)
            ->first();
    }
}