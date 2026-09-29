<?php

declare(strict_types=1);

namespace App\Service\Ai\Model;

use App\Entity\AiProviderSettings;

/** What addConfiguration() proves: the created row and the models offered, so choosing one needs no second listing. */
final readonly class AddedConfigurationModel
{
    /** @param list<string> $modelIds */
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public AiProviderSettings $configuration,
        public array $modelIds,
    ) {
    }
}
