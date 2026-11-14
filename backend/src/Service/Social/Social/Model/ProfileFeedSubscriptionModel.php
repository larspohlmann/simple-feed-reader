<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Support\BlueskyActorKey;

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

    public function handleOn(SocialNetwork $network): string
    {
        return match ($network) {
            SocialNetwork::Bluesky => BlueskyActorKey::isDid($this->identity) ? $this->identity : '@' . $this->identity,
            SocialNetwork::Mastodon => '@' . $this->identity,
            SocialNetwork::Lemmy => '!' . $this->identity,
        };
    }
}
