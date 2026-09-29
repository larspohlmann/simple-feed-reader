<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Pass;

use App\Entity\CallOutcome;
use App\Enum\CallVerdict;
use App\Repository\CallSettlement;
use App\Repository\RecommendationCallRepository;
use App\Service\Ai\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Ai\Completion\Model\CompletionStreamProgressModel;
use App\Service\Ai\Completion\Model\CompletionUsageModel;
use Symfony\Component\Clock\ClockInterface;

/** The stream observer for one recorded provider call: checkpoints its transcript and settles its run-log row. */
final class RecordedCall implements CompletionStreamObserverInterface
{
    private const int CHECKPOINT_SECONDS = 2;

    private \DateTimeImmutable $lastCheckpointAt;

    /**
     * Tracked on every report, not only on the ones that checkpoint, so the
     * final row records what the provider really sent rather than whatever
     * the last throttled write happened to catch.
     */
    private int $wireBytes = 0;

    /** Held until the call settles: a `length` beside an empty answer is a truncation. */
    private ?string $finishReason = null;

    /** Sticky: the usage arrives in one late message, so a later report without it must not erase it. */
    private ?CompletionUsageModel $usage = null;

    /** Billed once across every settle path; set only when bankUsage() writes, so a later path can still bank. */
    private bool $usageBanked = false;

    public function __construct(
        private readonly RecommendationCallRepository $calls,
        private readonly ClockInterface $clock,
        private readonly int $runId,
        private readonly int $logId,
    ) {
        // The recorder's begin() already wrote time zero, so the first checkpoint is due CHECKPOINT_SECONDS after it.
        $this->lastCheckpointAt = $clock->now();
    }

    public function streamProgressed(CompletionStreamProgressModel $progress): void
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
        $this->calls->recordTranscript($this->logId, $progress->answerSoFar, $progress->wireBytes);
    }

    public function finishUsable(string $content): void
    {
        $this->finish($content, CallVerdict::Usable);
    }

    public function finishUnusable(string $content): void
    {
        $this->finish($content, CallVerdict::Unusable);
    }

    /** Settles the row as a transport failure; its checkpoints stay, stamped with the byte count and the error. */
    public function abortAfterTransportFailure(?string $errorDetail): void
    {
        $this->resetLiveness();
        $this->bankUsage();

        $this->calls->settleTransportFailure(
            $this->settlement(CallVerdict::TransportFailed),
            $errorDetail,
        );
    }

    private function finish(string $content, CallVerdict $verdict): void
    {
        $this->resetLiveness();
        $this->bankUsage();

        $this->calls->settleAnswered($this->settlement($verdict), $content);
    }

    private function settlement(CallVerdict $verdict): CallSettlement
    {
        return new CallSettlement(
            $this->logId,
            new CallOutcome($verdict, $this->wireBytes, $this->clock->now(), $this->finishReason),
        );
    }

    private function resetLiveness(): void
    {
        $this->calls->recordStreamedChars($this->runId, 0);
    }

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
