<?php

declare(strict_types=1);

namespace App\Service\Operations\SelfUpdate;

use App\Service\Operations\SelfUpdate\Exception\NoUpdateAvailableException;
use App\Service\Operations\SelfUpdate\Exception\UpdateInProgressException;
use App\Service\Operations\SelfUpdate\Exception\UpdaterUnavailableException;
use App\Service\Operations\SelfUpdate\Model\SelfUpdatePhase;
use App\Service\Operations\SelfUpdate\Model\SelfUpdateStatusModel;
use App\Service\Operations\SelfUpdate\Model\UpdateRequestModel;
use App\Service\Operations\SelfUpdate\Model\UpdateRunModel;
use App\Service\Operations\SelfUpdate\UpdateStateStore\UpdateStateStoreInterface;
use App\Service\Operations\Version\VersionReporter;
use Psr\Clock\ClockInterface;

final readonly class SelfUpdater
{
    private const int HEARTBEAT_FRESH_SECONDS = 90;

    public function __construct(
        private UpdateStateStoreInterface $state,
        private VersionReporter $versions,
        private ClockInterface $clock,
    ) {
    }

    public function status(): SelfUpdateStatusModel
    {
        $pendingRequestId = $this->state->pendingRequestId();
        $currentRun = $this->currentRun($pendingRequestId);

        return new SelfUpdateStatusModel(
            $this->updaterPresent(),
            self::phase($currentRun, $pendingRequestId),
            $currentRun,
            null === $currentRun ? '' : $this->state->logTail(),
        );
    }

    public function request(string $requestedBy): void
    {
        if (!$this->updaterPresent()) {
            throw new UpdaterUnavailableException('No updater container is running.');
        }
        $pendingRequestId = $this->state->pendingRequestId();
        if (self::phase($this->currentRun($pendingRequestId), $pendingRequestId)->isUnderway()) {
            throw new UpdateInProgressException('An update is already under way.');
        }
        if (!$this->versions->report()->updateAvailable) {
            throw new NoUpdateAvailableException('This install already runs the latest release.');
        }

        $this->state->saveRequest(
            new UpdateRequestModel(bin2hex(random_bytes(8)), $requestedBy, $this->clock->now()),
        );
    }

    private function updaterPresent(): bool
    {
        $heartbeat = $this->state->heartbeatAt();

        return null !== $heartbeat
            && $this->clock->now()->getTimestamp() - $heartbeat->getTimestamp() <= self::HEARTBEAT_FRESH_SECONDS;
    }

    private function currentRun(?string $pendingRequestId): ?UpdateRunModel
    {
        $lastRun = $this->state->lastRun();

        return null === $pendingRequestId || $pendingRequestId === $lastRun?->requestId ? $lastRun : null;
    }

    private static function phase(?UpdateRunModel $currentRun, ?string $pendingRequestId): SelfUpdatePhase
    {
        return $currentRun->phase ?? (null === $pendingRequestId ? SelfUpdatePhase::Idle : SelfUpdatePhase::Requested);
    }
}
