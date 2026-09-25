<?php

declare(strict_types=1);

namespace App\Repository;

/** A feed's `keep`-th newest entry by (createdAt, id): the keyset the retention passes delete beyond. */
final readonly class EntryRankBoundary
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public int $id,
    ) {
    }
}
