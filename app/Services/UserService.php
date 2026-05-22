<?php

namespace App\Services;

use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Notifications\Auth\VerifyEmailNotification;

class UserService
{
    protected $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function registerUser(array $data, array $deviceData)
    {
        return DB::transaction(function() use ($data, $deviceData){
            $uuid = (string) Str::uuid();

            $user = $this->userRepository->create([
                'user_id' => $uuid,
                'email' => $data['email'],
                'status' => 'active'
            ]);

            $user->credential()->create([
                'password_hash' => Hash::make($data['password'])
            ]);
            
            $user->profile()->create([
                'full_name' => $data['full_name'],
                'created_at' => now()
            ]);

            $user->preference()->create([
                'language'            => 'vi',
                'theme'               => 'light',
                'currency'            => 'VND',
                'timezone'            => 'Asia/Ho_Chi_Minh',
                'financial_start_day' => 1,
                'created_at'          => now()
            ]);

            // 5. 🔥 TỰ CUSTOM TẠO SESSION & TOKEN NGAY TRONG SERVICE
            $accessToken = Str::random(60);  
            $refreshToken = Str::random(60); 

            $user->sessions()->create([
                'id'                 => (string) Str::uuid(),
                'refresh_token_hash' => hash('sha256', $refreshToken), 
                'device_type'        => $deviceData['device_type'],
                'device_name'        => $deviceData['device_name'],
                'ip_address'         => $deviceData['ip_address'],
                'user_agent'         => $deviceData['user_agent'],
                'expired_at'         => now()->addDays(30), 
                'created_at'         => now()
            ]);
            
            $user->notify(new VerifyEmailNotification($user));

            return [
                'user' => $user,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken
            ];
        });
    }
}