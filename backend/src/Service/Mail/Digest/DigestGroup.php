<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestGroup
{
    /** @param list<DigestEntry> $entries */
    public function __construct(
        public string $term,
        public int $totalCount,
        public array $entries,
        public bool $hasMore,
        public string $moreUrl,
    ) {
    }
}
