<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

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
        return str_starts_with($this->identity, 'did:') ? $this->identity : '@' . $this->identity;
    }
}
