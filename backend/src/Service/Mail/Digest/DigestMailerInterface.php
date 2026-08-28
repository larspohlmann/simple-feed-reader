<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;

interface DigestMailerInterface
{
    public function send(User $user, DigestModel $model): void;
}
