<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

use App\Entity\Tag;
use App\Entity\User;

final readonly class SubscribeRequestModel
{
    /** @param list<Tag> $tags */
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public User $user,
        public string $url,
        public array $tags,
    ) {
    }
}
