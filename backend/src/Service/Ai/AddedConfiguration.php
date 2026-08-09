<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;

/**
 * What a successful addConfiguration() call proves: the row it created, and
 * the models the provider offered when it verified the connection — so a
 * caller that wants to let the account choose one right away does not have
 * to spend a second outbound call just to re-list them.
 */
final readonly class AddedConfiguration
{
    /** @param list<string> $modelIds */
    public function __construct(
        public AiProviderSettings $configuration,
        public array $modelIds,
    ) {
    }
}
