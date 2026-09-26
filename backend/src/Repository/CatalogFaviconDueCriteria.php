<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * A catalog feed wants an icon when it has none or one older than $staleBefore, unless it failed after $retryBefore.
 */
final readonly class CatalogFaviconDueCriteria
{
    public function __construct(
        public \DateTimeImmutable $staleBefore,
        public \DateTimeImmutable $retryBefore,
    ) {
    }
}
