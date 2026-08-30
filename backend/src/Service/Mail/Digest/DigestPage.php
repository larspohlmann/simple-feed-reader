<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestPage
{
    /** @param list<DigestPageGroup> $groups */
    public function __construct(
        public array $groups,
        public int $totalCount,
    ) {
    }
}
