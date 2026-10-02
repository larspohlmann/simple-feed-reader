<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Factory\ProviderConnectionFactory;
use App\Service\Ai\Model\ProviderTimeoutsModel;
use App\Service\Recommendation\Engine\Model\RecommendationProfileSource;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Profile\ProfileConnectionResolver;

/**
 * One first-byte wait of the slowest connection the tick may call, plus the margin, not the whole tick: the keepalive
 * refreshes the lock on streamed chunks. Sizing: docs/recommendations-runs.md#the-tick-lock
 */
final readonly class TickLockTtl
{
    /**
     * Headroom over the longest silence a live holder produces: loading and packing before a request, banking between
     * waves, the whole snapshot tick. Public so the tests pin the TTL against its inputs.
     */
    public const float MARGIN_SECONDS = 300.0;

    public function __construct(
        private AiProviderConfigurator $configurator,
        private ProviderConnectionFactory $connectionFactory,
        private RecommendationEngineResolver $engines,
        private ProfileConnectionResolver $profileConnections,
    ) {
    }

    public function secondsFor(User $user): float
    {
        $active = $this->configurator->settingsFor($user);
        if (null === $active) {
            return ProviderTimeoutsModel::standard()->firstByteSeconds + self::MARGIN_SECONDS;
        }

        $profileConnection = $this->borrowedProfileConnection($user, $active);
        $calledConnections = null === $profileConnection ? [$active] : [$active, $profileConnection];

        return max(array_map($this->firstByteSeconds(...), $calledConnections)) + self::MARGIN_SECONDS;
    }

    private function borrowedProfileConnection(User $user, AiProviderSettings $active): ?AiProviderSettings
    {
        return RecommendationProfileSource::Borrowed === $this->engines->capabilitiesFor($active)->profileSource
            ? $this->profileConnections->findUsableFor($user)
            : null;
    }

    private function firstByteSeconds(AiProviderSettings $connection): float
    {
        return $this->connectionFactory->timeoutsFor($connection)->firstByteSeconds;
    }
}
