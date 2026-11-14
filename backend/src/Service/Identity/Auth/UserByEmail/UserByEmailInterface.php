<?php

declare(strict_types=1);

namespace App\Service\Auth\UserByEmail;

use App\Entity\User;

interface UserByEmailInterface
{
    public function findOneByEmail(string $email): ?User;
}
