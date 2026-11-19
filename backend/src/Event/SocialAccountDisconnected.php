<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\SocialAccount;

final readonly class SocialAccountDisconnected
{
    public function __construct(public SocialAccount $account)
    {
    }
}
