<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use App\Entity\AiProviderSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Null clears the account's cap and returns the batch ceiling to the packer's default. An omitted key must not read
 * as that null: AiSettingsController::setMaxBatchSize() maps this with REQUIRE_ALL_PROPERTIES.
 */
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
