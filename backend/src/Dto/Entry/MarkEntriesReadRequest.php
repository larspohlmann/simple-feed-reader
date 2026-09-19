<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use App\Service\Reader\MarkEntriesReadService;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkEntriesReadRequest
{
    /** @param list<int> $ids */
    public function __construct(
        #[Assert\Count(min: 1, max: MarkEntriesReadService::MAX_IDS)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $ids = [],
    ) {
    }
}
