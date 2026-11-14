<?php

declare(strict_types=1);

namespace App\Service\Curation\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Curation\Ai\Exception\ConfigurationNotFoundException;

/**
 * Finds the configurations an account owns. A route's `{id}` resolves through require(): another account's row reads
 * as missing (404, not 403), so a caller never learns an id exists.
 */
final readonly class AiConfigurationForUser
{
    public function __construct(private AiProviderSettingsRepository $aiProviderSettings)
    {
    }

    /**
     * @return list<AiProviderSettings> oldest first
     */
    public function all(User $user): array
    {
        return $this->aiProviderSettings->findAllForUser($user);
    }

    /**
     * @throws ConfigurationNotFoundException
     */
    public function require(User $user, int $id): AiProviderSettings
    {
        return $this->aiProviderSettings->findOneForUser($user, $id)
            ?? throw new ConfigurationNotFoundException(sprintf('No AI configuration %d for this account.', $id));
    }
}
