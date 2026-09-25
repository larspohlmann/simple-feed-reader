<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Recommendation\CompletionUsage;
use Doctrine\DBAL\Connection;

/**
 * A recorded provider call's writes, on DBAL on purpose: they commit at once for the status poll, and never flush
 * what the advancer's EntityManager holds dirty mid-tick.
 */
final readonly class RecommendationCallRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function recordStreamedChars(int $runId, int $streamedChars): void
    {
        $this->connection->update(
            'recommendation_run',
            ['streamed_chars' => $streamedChars],
            ['id' => $runId],
        );
    }

    public function recordTranscript(int $logId, string $answerSoFar, int $wireBytes): void
    {
        $this->connection->update(
            'recommendation_run_log',
            ['response_text' => $answerSoFar, 'wire_bytes' => $wireBytes],
            ['id' => $logId],
        );
    }

    public function settleAnswered(CallSettlement $settlement, string $content): void
    {
        $this->connection->update('recommendation_run_log', [
            'response_text' => $content,
            'verdict' => $settlement->verdict,
            'wire_bytes' => $settlement->wireBytes,
            'finished_at' => $settlement->finishedAt->format('Y-m-d H:i:s'),
            'finish_reason' => $settlement->finishReason,
        ], ['id' => $settlement->logId]);
    }

    public function settleTransportFailure(CallSettlement $settlement, ?string $errorDetail): void
    {
        $this->connection->update('recommendation_run_log', [
            'verdict' => $settlement->verdict,
            'wire_bytes' => $settlement->wireBytes,
            'finished_at' => $settlement->finishedAt->format('Y-m-d H:i:s'),
            'error_detail' => $errorDetail,
            'finish_reason' => $settlement->finishReason,
        ], ['id' => $settlement->logId]);
    }

    /** SQL arithmetic, not read-modify-write: a #344 wave settles several calls against one run. */
    public function addUsage(int $runId, CompletionUsage $usage): void
    {
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
                'runId' => $runId,
            ],
        );

        $this->addCost($runId, $usage->costNanoCredits);
    }

    /** An unpriced call leaves the column NULL: null means no price was reported, 0 would claim the run was free. */
    private function addCost(int $runId, ?int $costNanoCredits): void
    {
        if (null === $costNanoCredits) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE recommendation_run'
            . ' SET cost_nano_credits = COALESCE(cost_nano_credits, 0) + :costNanoCredits'
            . ' WHERE id = :runId',
            ['costNanoCredits' => $costNanoCredits, 'runId' => $runId],
        );
    }
}
