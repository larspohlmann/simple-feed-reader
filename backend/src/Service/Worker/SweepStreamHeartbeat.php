<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Recommendation\CompletionStreamHeartbeat;
use Symfony\Component\Clock\ClockInterface;

/**
 * Keeps a sweeping worker's liveness fresh while it sits inside a provider
 * call (#433).
 *
 * The sweep marks liveness once before each run, which was enough while a
 * single call could not outlast WorkerPresence::FRESH_SECONDS. A connection on
 * the slow profile can hold one call for an hour, and a worker that only
 * marked at the start of it would read as dead for most of that: the poll
 * driver would stop deferring to it and the settings card would report no
 * worker, both while it was doing exactly what it was asked to do.
 *
 * So the transport pings this as each chunk arrives, and the ping is a write
 * only every MINIMUM_INTERVAL_SECONDS — a streamed answer delivers deltas many
 * times a second, and each one is a row update.
 *
 * It answers only while a sweep is running. Nothing arms it in a web request,
 * so a poll tick's provider call pings a no-op: a web request is not a worker
 * and must never claim to be one, which is the whole distinction the poll
 * driver reads.
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
