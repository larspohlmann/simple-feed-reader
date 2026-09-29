<?php

declare(strict_types=1);

namespace App\Repository;

/** The DQL aliases a scope predicate reads, so one scope serves the outer query and the duplicate-collapse join. */
final readonly class EntryAliases
{
    public function __construct(
        public string $entry,
        public string $state,
        public string $subscription,
        public string $tag,
    ) {
    }

    public static function primary(): self
    {
        return new self('e', 'es', 's', 'st');
    }

    public static function collapse(): self
    {
        return new self('e2', 'es2', 's2', 'st2');
    }
}
