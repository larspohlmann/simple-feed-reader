<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Event\SocialAccountDisconnected;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class BlueskySessionForgetListener
{
    public function __construct(private BlueskySessionCache $sessionCache)
    {
    }

    public function __invoke(SocialAccountDisconnected $event): void
    {
        $this->sessionCache->forget($event->account);
    }
}
