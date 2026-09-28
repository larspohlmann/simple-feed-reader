<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Model;

final readonly class DigestPageGroupModel
{
    /** @param list<DigestEntryModel> $cards */
    public function __construct(
        public string $term,
        public int $totalCount,
        public array $cards,
        public int $remaining,
        public string $moreUrl,
    ) {
    }
}
