<?php

namespace App\Services;

use App\Repositories\Contracts\UserRepositoryInterface;

class UserService
{
    protected $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function registerUser(array $data)
    {
        // Logic nghiệp vụ: Hash password, tạo user, gửi email xác nhận...
        $user = $this->userRepository->create([
            'email' => $data['email'],
            'status' => 'active'
        ]);

        return $user;
    }
}