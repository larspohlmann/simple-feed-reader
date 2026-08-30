<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestPageGroup
{
    /** @param list<DigestEntry> $cards */
    public function __construct(
        public string $term,
        public int $totalCount,
        public array $cards,
        public int $remaining,
        public string $moreUrl,
    ) {
    }
}
