<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps a sweeping driver's liveness fresh during a provider call, which can outlast FRESH_SECONDS: the transport pings
 * it per chunk, one write per MINIMUM_INTERVAL_SECONDS at most. Only a sweep arms it, the cron's included; a browser
 * poll tick's call pings a no-op, because a poll tick must never claim to be a driver.
 */
final class SweepStreamHeartbeat implements CompletionStreamHeartbeatInterface, ResetInterface
{
    /**
     * Far below FRESH_SECONDS, so the gap between two writes cannot be
     * mistaken for silence, and far above the delta rate, so a streaming
     * answer costs a couple of writes a minute rather than thousands.
     */
    private const int MINIMUM_INTERVAL_SECONDS = 30;

    private ?RecommendationDriverKind $sweepingAs = null;

    private ?\DateTimeImmutable $lastBeatAt = null;

    public function __construct(
        private readonly WorkerPresence $presence,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Arms the heartbeat for the duration of one sweep, under the key of the
     * regime doing the sweeping — the same key that sweep marks between runs,
     * because a beat mid-call and a mark between runs are the same claim.
     */
    public function sweepStarted(RecommendationDriverKind $kind): void
    {
        $this->sweepingAs = $kind;
        $this->lastBeatAt = null;
    }

    /**
     * Disarms it. A sweep that has ended is no longer evidence of anything,
     * and the drain command surrenders its liveness key outright when it
     * exits — a heartbeat left armed could write that key back afterwards.
     */
    public function sweepEnded(): void
    {
        $this->sweepingAs = null;
        $this->lastBeatAt = null;
    }

    public function reset(): void
    {
        $this->sweepEnded();
    }

    public function beat(): void
    {
        if (null === $this->sweepingAs || !$this->isDue()) {
            return;
        }

        $this->lastBeatAt = $this->clock->now();
        $this->presence->mark($this->sweepingAs);
    }

    private function isDue(): bool
    {
        if (null === $this->lastBeatAt) {
            return true;
        }

        return $this->clock->now()->getTimestamp() - $this->lastBeatAt->getTimestamp()
            >= self::MINIMUM_INTERVAL_SECONDS;
    }
}
