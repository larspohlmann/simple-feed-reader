<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use App\Entity\AiProviderSettings;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetBatchConcurrencyRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: AiProviderSettings::MAX_BATCH_CONCURRENCY)]
        public int $batchConcurrency,
    ) {
    }
}
