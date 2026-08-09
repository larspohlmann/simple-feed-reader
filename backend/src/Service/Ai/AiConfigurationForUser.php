<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Exception\ConfigurationNotFoundException;

/**
 * The one place that turns a route's `{id}` into a row, scoped to the
 * account making the request. Every `{id}` route in AiSettingsController goes
 * through here rather than through the repository directly, so ownership is
 * checked once and cannot be forgotten on a new route.
 *
 * A row belonging to another account is reported the same way a missing id
 * is — 404, not 403 — so a caller cannot learn that an id exists at all.
 */
final readonly class AiConfigurationForUser
{
    public function __construct(private AiProviderSettingsRepository $repository)
    {
    }

    /**
     * @throws ConfigurationNotFoundException
     */
    public function require(User $user, int $id): AiProviderSettings
    {
        return $this->repository->findOwnedById($user, $id)
            ?? throw new ConfigurationNotFoundException(sprintf('No AI configuration %d for this account.', $id));
    }
}
