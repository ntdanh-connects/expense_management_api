<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Models\User;

class UserRepository extends BaseRepository implements UserRepositoryInterface {

    public function getModel()
    {
        return User::class;
    }

    public function findByEmail(string $email)
    {
        return $this->model->where('email', $email)->first();
    }
}