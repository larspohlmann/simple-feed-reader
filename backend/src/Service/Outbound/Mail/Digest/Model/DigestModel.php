<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Digest\Model;

final readonly class DigestModel
{
    /** @param list<DigestGroupModel> $groups */
    public function __construct(
        public array $groups,
        public int $totalCount,
    ) {
    }
}
