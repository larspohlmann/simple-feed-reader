<?php

declare(strict_types=1);

namespace App\Repository;

/** The DQL aliases a scope rule reads, so one rule serves the list, its duplicate-collapse join and the unread counts. */
final readonly class EntryAliases
{
    public function __construct(
        public string $entry,
        public string $state,
        public string $subscription,
        public string $tag,
        public string $networkFeed,
        public string $groupFeed,
    ) {
    }

    public static function primary(): self
    {
        return new self('e', 'es', 's', 'st', 'nf', 'gf');
    }

    public static function collapse(): self
    {
        return new self('e2', 'es2', 's2', 'st2', 'nf2', 'gf2');
    }

    public function subscriptionKey(): string
    {
        return $this->subscription . '.id';
    }

    public function tagKey(): string
    {
        return \sprintf('IDENTITY(%s.tag)', $this->tag);
    }

    public function networkKey(): string
    {
        return $this->networkFeed . '.network';
    }
}
