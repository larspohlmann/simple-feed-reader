<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Exception\ConfigurationNotFoundException;

/**
 * Turns a route's `{id}` into a row the requesting account owns; every `{id}` route in AiSettingsController goes
 * through here. Another account's row reads as missing (404, not 403), so a caller never learns an id exists.
 */
final readonly class AiConfigurationForUser
{
    public function __construct(private AiProviderSettingsRepository $aiProviderSettings)
    {
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
