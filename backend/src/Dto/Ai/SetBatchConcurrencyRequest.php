<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use App\Entity\RunTuning;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetBatchConcurrencyRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: RunTuning::MAX_BATCH_CONCURRENCY)]
        public int $batchConcurrency,
    ) {
    }
}
