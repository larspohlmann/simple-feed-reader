<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The four DQL aliases the entry-list scope predicates read. Two fixed sets:
 * the primary query (e/es/s/st) and the duplicate-collapse semi-join
 * (e2/es2/s2/st2), so one scope definition serves the outer query and the
 * NOT EXISTS that hides its lower-id copies.
 */
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
