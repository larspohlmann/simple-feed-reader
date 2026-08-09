<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRunLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * The stream observer for one recorded provider call (#309). Checkpoints go
 * through the DBAL connection, not the EntityManager, on purpose: they must
 * commit immediately (the cheap status poll reads them while the tick
 * request is still blocked on the provider), and they must not flush
 * whatever else the advancer's EntityManager holds dirty mid-tick.
 *
 * Deliberately not readonly: a call is a short-lived session whose one piece
 * of state is when it last checkpointed. `$logId` null means debug is off —
 * the liveness counter is still maintained, the transcript is not.
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

    /**
     * Held like $wireBytes, not written until the call settles: the provider
     * stamps it near the end of the stream, and the settled row is where it
     * explains the outcome — a `length` beside an empty answer is a truncation,
     * not silence (#327).
     */
    private ?string $finishReason = null;

    public function __construct(
        private readonly Connection $connection,
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

        $now = $this->clock->now();
        if ($now->getTimestamp() - $this->lastCheckpointAt->getTimestamp() < self::CHECKPOINT_SECONDS) {
            return;
        }
        $this->lastCheckpointAt = $now;

        $this->connection->update(
            'recommendation_run',
            ['streamed_chars' => $progress->wireBytes],
            ['id' => $this->runId],
        );

        if (null === $this->logId) {
            return;
        }

        $this->connection->update(
            'recommendation_run_log',
            ['response_text' => $progress->answerSoFar, 'wire_bytes' => $progress->wireBytes],
            ['id' => $this->logId],
        );
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
     * The stream died mid-answer: whatever the checkpoints salvaged stays,
     * stamped with the transport verdict so the panel can say so. The byte
     * count is what makes that row readable — a call that streamed megabytes
     * of reasoning without answering is a different story from a provider
     * that said nothing, and only this number tells them apart (#320). The
     * transport exception's message is recorded too, so a stalled or failing
     * run can be diagnosed from the log alone rather than a live tail of the
     * server's own error output.
     */
    public function abortAfterTransportFailure(?string $errorDetail): void
    {
        $this->resetLiveness();

        if (null === $this->logId) {
            return;
        }

        $this->connection->update('recommendation_run_log', [
            'verdict' => RecommendationRunLog::VERDICT_TRANSPORT_FAILED,
            'wire_bytes' => $this->wireBytes,
            'finished_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'error_detail' => $errorDetail,
            'finish_reason' => $this->finishReason,
        ], ['id' => $this->logId]);
    }

    private function finish(string $content, string $verdict): void
    {
        $this->resetLiveness();

        if (null === $this->logId) {
            return;
        }

        $this->connection->update('recommendation_run_log', [
            'response_text' => $content,
            'verdict' => $verdict,
            'wire_bytes' => $this->wireBytes,
            'finished_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'finish_reason' => $this->finishReason,
        ], ['id' => $this->logId]);
    }

    private function resetLiveness(): void
    {
        $this->connection->update('recommendation_run', ['streamed_chars' => 0], ['id' => $this->runId]);
    }
}
