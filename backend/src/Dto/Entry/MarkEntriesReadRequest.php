<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkEntriesReadRequest
{
    public const int MAX_IDS = 5000;

    /** @param list<int> $ids */
    public function __construct(
        #[Assert\Count(min: 1, max: self::MAX_IDS)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $ids = [],
    ) {
    }
}
