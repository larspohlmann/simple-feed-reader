<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestModel
{
    /** @param list<DigestGroup> $groups */
    public function __construct(
        public array $groups,
        public int $totalCount,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }
}
