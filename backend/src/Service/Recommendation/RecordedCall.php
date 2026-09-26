<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\CallOutcome;
use App\Entity\RecommendationRunLog;
use App\Repository\CallSettlement;
use App\Repository\RecommendationCallRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The stream observer for one recorded provider call (#309). Not readonly: its one piece of state is when it last
 * checkpointed. `$logId` null means debug is off — liveness is still kept, the transcript is not.
 */
final class RecordedCall implements CompletionStreamObserver
{
    /** The issue's ~2 s pseudo-streaming cadence. */
    private const int CHECKPOINT_SECONDS = 2;

    private \DateTimeImmutable $lastCheckpointAt;

    /**
     * Tracked on every report, not only on the ones that checkpoint, so the
     * final row records what the provider really sent rather than whatever
     * the last throttled write happened to catch.
     */
    private int $wireBytes = 0;

    /** Held until the call settles: a `length` beside an empty answer is a truncation (#327). */
    private ?string $finishReason = null;

    /**
     * The provider's own accounting for this call, held like $finishReason
     * and banked when the call settles (#409). Sticky: it arrives in one late
     * message, so a later report without it must not erase it.
     */
    private ?CompletionUsage $usage = null;

    /**
     * Billed once per instance across every settle path; set only once
     * bankUsage() writes, so a later path can still bank (#344, #409).
     */
    private bool $usageBanked = false;

    public function __construct(
        private readonly RecommendationCallRepository $calls,
        private readonly ClockInterface $clock,
        private readonly int $runId,
        private readonly ?int $logId,
    ) {
        // The interval is armed at begin() time: begin() already persisted
        // everything worth persisting at time zero, so the first checkpoint
        // is due CHECKPOINT_SECONDS after the call went out.
        $this->lastCheckpointAt = $clock->now();
    }

    public function streamProgressed(CompletionStreamProgress $progress): void
    {
        $this->wireBytes = $progress->wireBytes;
        $this->finishReason = $progress->finishReason ?? $this->finishReason;
        $this->usage = $progress->usage ?? $this->usage;

        $now = $this->clock->now();
        if ($now->getTimestamp() - $this->lastCheckpointAt->getTimestamp() < self::CHECKPOINT_SECONDS) {
            return;
        }
        $this->lastCheckpointAt = $now;

        $this->calls->recordStreamedChars($this->runId, $progress->wireBytes);

        if (null === $this->logId) {
            return;
        }

        $this->calls->recordTranscript($this->logId, $progress->answerSoFar, $progress->wireBytes);
    }

    public function finishUsable(string $content): void
    {
        $this->finish($content, RecommendationRunLog::VERDICT_USABLE);
    }

    public function finishUnusable(string $content): void
    {
        $this->finish($content, RecommendationRunLog::VERDICT_UNUSABLE);
    }

    /**
     * Settles this call with the parser's verdict on $content: usable banks
     * it as the answer, unusable records it as the invalid reply the next
     * retry corrects against.
     */
    public function settle(string $content, bool $usable): void
    {
        if ($usable) {
            $this->finishUsable($content);

            return;
        }

        $this->finishUnusable($content);
    }

    /** The stream died mid-answer: the salvaged checkpoints stay, stamped with the byte count and the error (#320). */
    public function abortAfterTransportFailure(?string $errorDetail): void
    {
        $this->resetLiveness();
        $this->bankUsage();

        if (null === $this->logId) {
            return;
        }

        $this->calls->settleTransportFailure(
            $this->settlement($this->logId, RecommendationRunLog::VERDICT_TRANSPORT_FAILED),
            $errorDetail,
        );
    }

    private function finish(string $content, string $verdict): void
    {
        $this->resetLiveness();
        $this->bankUsage();

        if (null === $this->logId) {
            return;
        }

        $this->calls->settleAnswered($this->settlement($this->logId, $verdict), $content);
    }

    private function settlement(int $logId, string $verdict): CallSettlement
    {
        return new CallSettlement(
            $logId,
            new CallOutcome($verdict, $this->wireBytes, $this->clock->now(), $this->finishReason),
        );
    }

    private function resetLiveness(): void
    {
        $this->calls->recordStreamedChars($this->runId, 0);
    }

    /** Runs before the debug guard in both callers: a spend record must not depend on the debug switch (#409). */
    private function bankUsage(): void
    {
        $usage = $this->usage;

        if (null === $usage || $this->usageBanked) {
            return;
        }
        $this->usageBanked = true;

        $this->calls->addUsage($this->runId, $usage);
    }
}
