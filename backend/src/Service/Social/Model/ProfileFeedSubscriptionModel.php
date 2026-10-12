<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Social\Support\ProfileFeedUrl;

final readonly class ProfileFeedSubscriptionModel
{
    public function __construct(
        public int $subscriptionId,
        public string $identity,
        public string $feedUrl,
        public string $title,
    ) {
    }

    public function handle(): string
    {
        return ProfileFeedUrl::isDid($this->identity) ? $this->identity : '@' . $this->identity;
    }
}
