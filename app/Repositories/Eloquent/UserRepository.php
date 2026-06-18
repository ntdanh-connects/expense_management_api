<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
            ->whereNull('revoked_at')
            ->where('refresh_token_hash',$hashedToken)
            ->first();
    }

    public function findWithRelations(string $userId)
    {
        $data = DB::table('users')
        ->leftJoin('user_profiles', 'users.user_id', '=', 'user_profiles.user_id')
        ->leftJoin('user_preferences', 'users.user_id', '=', 'user_preferences.user_id')
        ->where('users.user_id', $userId)
        ->select(
            'users.user_id', 'users.email', 'users.status', 'users.email_verified_at',
            'user_profiles.full_name', 'user_profiles.avatar_url',
            'user_preferences.currency', 'user_preferences.language', 'user_preferences.theme', 'user_preferences.timezone'
        )
        ->first();

    if (!$data) return null;

    // Trả về định dạng Object cấu trúc phân cấp chuẩn của ní
    return (object)[
        'user_id'           => $data->user_id,
        'email'             => $data->email,
        'status'            => $data->status,
        'email_verified_at' => $data->email_verified_at,
        'profile' => [
            'full_name'  => $data->full_name ?? '',
            'avatar_url' => $data->avatar_url ?? '',
        ],
        'preference' => [
            'currency' => $data->currency ?? 'VND',
            'language' => $data->language ?? 'vi',
            'theme'    => $data->theme ?? 'light',
            'timezone' => $data->timezone ?? 'UTC',
        ]
    ];
    }
}