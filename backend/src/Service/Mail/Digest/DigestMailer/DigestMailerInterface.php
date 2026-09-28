<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestMailer;

use App\Entity\User;
use App\Service\Mail\Digest\DigestModel;

interface DigestMailerInterface
{
    public function send(User $user, DigestModel $model): void;
}
