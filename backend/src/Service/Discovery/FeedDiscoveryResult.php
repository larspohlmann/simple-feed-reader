<?php

declare(strict_types=1);

namespace App\Service\Discovery;

final readonly class FeedDiscoveryResult
{
    /** @param list<FeedCandidate> $candidates */
    private function __construct(
        public bool $isDirectFeed,
        public ?string $feedUrl,
        public array $candidates,
    ) {
    }

    public static function directFeed(string $feedUrl): self
    {
        return new self(true, $feedUrl, []);
    }

    /** @param list<FeedCandidate> $candidates */
    public static function candidates(array $candidates): self
    {
        return new self(false, null, $candidates);
    }
}
