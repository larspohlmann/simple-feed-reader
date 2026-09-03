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

    /**
     * The provider's own accounting for this call, held like $finishReason
     * and banked when the call settles (#409). Sticky: it arrives in one late
     * message, so a later report without it must not erase it.
     */
    private ?CompletionUsage $usage = null;

    /**
     * One provider call is billed once. Every settle path -- a verdict, a transport
     * abort, a wave that aborts a call the round already settled -- runs through
     * bankUsage(), and without this flag a call reachable by two of them would double
     * its own spend. Per-instance on purpose: a retry and the discarded sibling of an
     * aborted wave are separate RecordedCalls, each billed by the provider (#344). Only
     * set once bankUsage() actually writes, so a settle that finds no usage yet (a
     * transport failure before the provider's usage message arrived) leaves this false
     * for a later settle path to still bank it.
     */
    private bool $usageBanked = false;

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
        $this->usage = $progress->usage ?? $this->usage;

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

    /**
     * The stream died mid-answer: whatever the checkpoints salvaged stays, stamped with
     * the transport verdict so the panel can say so. The byte count makes that row
     * readable -- megabytes of reasoning without answering is a different story from a
     * provider that said nothing, and only this number tells them apart (#320). The
     * transport exception's message is recorded too, so a stalled or failing run can be
     * diagnosed from the log alone rather than a live tail of the server's own error
     * output.
     */
    public function abortAfterTransportFailure(?string $errorDetail): void
    {
        $this->resetLiveness();
        $this->bankUsage();

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
        $this->bankUsage();

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

    /**
     * Adds this call's consumption to the run's own totals -- with SQL arithmetic, not
     * read-modify-write: a #344 wave settles several calls against one run, and two
     * PHP-side increments would silently lose one of them. Through DBAL rather than the
     * EntityManager for the reason every write in this class is: the advancer holds other
     * work dirty mid-tick, and flushing it here would commit that too.
     *
     * Runs before the debug guard in both callers on purpose: the RecordedCall exists
     * whether or not the debug switch is on, and a spending record that only exists with
     * debug on is the defect #409 was filed about.
     */
    private function bankUsage(): void
    {
        $usage = $this->usage;

        if (null === $usage || $this->usageBanked) {
            return;
        }
        $this->usageBanked = true;

        $this->connection->executeStatement(
            'UPDATE recommendation_run SET'
            . ' prompt_tokens = prompt_tokens + :promptTokens,'
            . ' completion_tokens = completion_tokens + :completionTokens,'
            . ' reasoning_tokens = reasoning_tokens + :reasoningTokens,'
            . ' cached_tokens = cached_tokens + :cachedTokens'
            . ' WHERE id = :runId',
            [
                'promptTokens' => $usage->promptTokens,
                'completionTokens' => $usage->completionTokens,
                'reasoningTokens' => $usage->reasoningTokens,
                'cachedTokens' => $usage->cachedTokens,
                'runId' => $this->runId,
            ],
        );

        $this->bankCost($usage->costNanoCredits);
    }

    /**
     * The price, kept out of the token statement so an unpriced call leaves
     * the column NULL rather than coercing it to 0 — null means "no provider
     * reported a price", and 0 would claim the run was free. COALESCE is what
     * makes the first priced call of a run initialise the column.
     */
    private function bankCost(?int $costNanoCredits): void
    {
        if (null === $costNanoCredits) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE recommendation_run'
            . ' SET cost_nano_credits = COALESCE(cost_nano_credits, 0) + :costNanoCredits'
            . ' WHERE id = :runId',
            ['costNanoCredits' => $costNanoCredits, 'runId' => $this->runId],
        );
    }
}
