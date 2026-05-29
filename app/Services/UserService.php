<?php

namespace App\Services;

use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Notifications\Auth\VerifyEmailNotification;
use Exception;

class UserService
{
    protected $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function registerUser(array $data)
    {
        return DB::transaction(function() use ($data){
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
            
            $user->notify(new VerifyEmailNotification($user));

            return $user;
        });
    }

    public function loginUser(array $data, array $deviceData){
        $user = $this->userRepository->findByEmail($data['email']);

        if(!$user || !Hash::check($data['password'], $user->credential->password_hash)){
            throw new \Exception("Email hoặc mật khẩu không chính xác !");
        }

        if($user->status === 'suspended'){
            throw new \Exception('Tài khoản của bạn hiện tạm dừng');
        }

        if(is_null($user->email_verified_at)){
            throw new \Exception('Hiện tại email chưa được kích hoạt !, vui lòng truy cập mail để xác thực tài khoản');
        }

        $accessToken = Str::random(60);
        $refreshToken = Str::random(60);

        $existingSession = $user->sessions()->where(
            'user_agent', $deviceData['user_agent']
        )->first();

        if($existingSession){
            $existingSession->update([
                'refresh_token_hash' => hash('sha256', $refreshToken),
                'access_token_hash'  => hash('sha256', $accessToken),
                'ip_address'=> $deviceData['ip_address'],
                'expired_at' => now()->addDays(30)
            ]);
        }else{
             $user->sessions()->create([
            'id'                 => (string) Str::uuid(),
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'access_token_hash'  => hash('sha256', $accessToken),
            'device_type'        => $deviceData['device_type'],
            'device_name'        => $deviceData['device_name'],
            'ip_address'         => $deviceData['ip_address'],
            'user_agent'         => $deviceData['user_agent'],
            'expired_at'         => now()->addDays(30),
            'created_at'         => now()
            ]);
        }
        return [
            'user'          => $user,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken
        ];
    }

    public function refreshToken(string $userId, string $refreshToken){
        $hashedToken = hash('sha256',$refreshToken);

        $session = $this->userRepository->findSessionbyToken($userId,$hashedToken);

        if(!$session){
            throw new \Exception("Phiên làm việc đã hết hạn hoặc mã xác thực không hợp lệ, vui lòng đăng nhập lại!");
        }

        $newAccessToken = Str::random(60);
        $newRefreshToken = Str::random(60);

        DB::table('user_sessions')->where('id',$session->id)->update([
            'refresh_token_hash' => hash('sha256',$newRefreshToken),
            'access_token_hash'  => hash('sha256',$newAccessToken),
            'expired_at'=> now()->addDays(30)
        ]);

        $user = $this->userRepository->find($userId);

        return [
            'user'=> $user,
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken
        ];
    }

    public function getUserProfile(string $userId)
    {
        $user = $this->userRepository->findWithRelations($userId);

        if (!$user) {
            throw new \Exception("Không tìm thấy thông tin người dùng trong hệ thống!");
        }

        if ($user->status === 'suspended') {
            throw new \Exception("Tài khoản của bạn đã bị khóa, vui lòng liên hệ ban quản trị!");
        }

        return $user;
    }
}