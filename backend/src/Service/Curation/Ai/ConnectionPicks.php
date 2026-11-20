<?php

declare(strict_types=1);

namespace App\Service\Curation\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;

/** A feature's saved connection pick, judged by whether a connection can serve that feature. */
final readonly class ConnectionPicks
{
    public function __construct(private AiConfigurationForUser $configurations)
    {
    }

    /**
     * The stored pick if it can serve, else none; with nothing picked, the active connection if it can.
     *
     * @param \Closure(AiProviderSettings): bool $canServe
     */
    public function findUsableConnectionGiven(
        User $user,
        ?AiProviderSettings $storedPick,
        \Closure $canServe,
    ): ?AiProviderSettings {
        $candidate = $storedPick ?? $user->getActiveAiProviderSettings();

        return null !== $candidate && $canServe($candidate) ? $candidate : null;
    }

    /**
     * @param \Closure(AiProviderSettings): bool $canServe
     *
     * @return list<AiProviderSettings>
     */
    public function findCandidates(User $user, \Closure $canServe): array
    {
        return array_values(array_filter($this->configurations->all($user), $canServe));
    }

    /**
     * The stored pick stays allowed after it stopped serving, so saving the other settings never rejects it.
     *
     * @param \Closure(AiProviderSettings): bool $canServe
     */
    public function allows(AiProviderSettings $connection, ?AiProviderSettings $storedPick, \Closure $canServe): bool
    {
        return $connection === $storedPick || $canServe($connection);
    }
}
