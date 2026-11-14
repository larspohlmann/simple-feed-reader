<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Bluesky\Support\BlueskyActorKey;

final readonly class ProfileFeedSubscriptionModel
{
    public function __construct(
        public int $subscriptionId,
        public string $identity,
        public string $feedUrl,
        public string $title,
        public ?string $imageUrl,
    ) {
    }

    public function handle(): string
    {
        return BlueskyActorKey::isDid($this->identity) ? $this->identity : '@' . $this->identity;
    }
}
