<?php

namespace App\Repositories\Contracts;

use PharIo\Manifest\Email;

interface UserRepositoryInterface extends BaseRepositoryInterface {

    public function findByEmail(string $email);
}