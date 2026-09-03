<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Recommendation\CompletionStreamHeartbeat;
use Symfony\Component\Clock\ClockInterface;

/**
 * Keeps a sweeping worker's liveness fresh while it sits inside a provider
 * call (#433).
 *
 * The sweep used to mark liveness once before each run, fine while a single
 * call could not outlast WorkerPresence::FRESH_SECONDS. A connection on the
 * slow profile can hold one call for an hour, and a worker only marked at the
 * start would read as dead for most of it — the poll driver stops deferring
 * and the settings card reports no worker, both while it is doing exactly
 * what it was asked to do.
 *
 * So the transport pings this as each chunk arrives, throttled to a write
 * every MINIMUM_INTERVAL_SECONDS — a streamed answer delivers deltas many
 * times a second, and each one is a row update.
 *
 * It answers only while a sweep is running, and only a sweep arms it. A
 * browser poll tick's provider call therefore pings a no-op: it advances the
 * watching account's own run and must never claim to be a worker — the whole
 * distinction the poll driver reads. The cron sweep does arm it (#439): it
 * runs in a web request too, but drives every account's run on the install's
 * behalf, which is what a driver kind means here.
 */
final class SweepStreamHeartbeat implements CompletionStreamHeartbeat
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
