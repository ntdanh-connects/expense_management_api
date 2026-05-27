<?php

namespace App\Repositories\Contracts;


interface UserRepositoryInterface extends BaseRepositoryInterface {
    public function findByEmail(string $email);
    public function findSessionbyToken(string $userId, string $hashedToken);
    public function findWithRelations(string $userId);
}