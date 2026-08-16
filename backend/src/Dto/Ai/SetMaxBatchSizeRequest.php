<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use App\Entity\AiProviderSettings;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetMaxBatchSizeRequest
{
    public function __construct(
        #[Assert\Range(
            min: AiProviderSettings::MINIMUM_BATCH_SIZE,
            max: AiProviderSettings::MAXIMUM_BATCH_SIZE,
        )]
        public ?int $maxBatchSize,
    ) {
    }
}
