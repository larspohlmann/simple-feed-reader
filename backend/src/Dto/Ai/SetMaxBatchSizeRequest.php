<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use App\Entity\AiProviderSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nullable on purpose: null is the account clearing its per-connection cap and
 * returning the batch ceiling to the packer's own default. That is why this
 * one payload is mapped with AbstractNormalizer::REQUIRE_ALL_PROPERTIES --
 * see AiSettingsController::setMaxBatchSize() -- without which an omitted key
 * would arrive here as that same null and clear the cap by accident.
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
