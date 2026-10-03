<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Factory\ProviderConnectionFactory;
use App\Service\Ai\Model\ProviderTimeoutsModel;
use App\Service\Recommendation\Profile\ProfileConnectionResolver;

/**
 * One first-byte wait of the slowest connection the tick may call, plus the margin, not the whole tick: the keepalive
 * refreshes the lock on streamed chunks. Sizing: docs/recommendations-runs.md#the-tick-lock
 */
final readonly class TickLockTtl
{
    /**
     * Headroom over the longest silence a live holder produces: loading and packing before a request, banking between
     * waves, the whole snapshot tick.
     */
    public const float MARGIN_SECONDS = 300.0;

    public function __construct(
        private AiProviderConfigurator $configurator,
        private ProviderConnectionFactory $connectionFactory,
        private ProfileConnectionResolver $profileConnections,
    ) {
    }

    public function secondsFor(User $user): float
    {
        $active = $this->configurator->settingsFor($user);
        $borrowed = null === $active ? null : $this->profileConnections->borrowedFor($active);
        if (null === $borrowed) {
            return $this->secondsForConnection($active);
        }

        return max($this->secondsForConnection($active), $this->secondsForConnection($borrowed));
    }

    /** No connection gets the standard bound: a tick that only fails its run still holds the lock briefly. */
    public function secondsForConnection(?AiProviderSettings $connection): float
    {
        $timeouts = null === $connection
            ? ProviderTimeoutsModel::standard()
            : $this->connectionFactory->timeoutsFor($connection);

        return $timeouts->firstByteSeconds + self::MARGIN_SECONDS;
    }
}
