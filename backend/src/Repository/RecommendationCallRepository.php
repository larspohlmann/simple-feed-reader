<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallFailure;
use App\Entity\CallOutcome;
use App\Entity\CallReceipt;
use App\Enum\CallVerdict;
use App\Service\Curation\Ai\Model\ProviderCallUsageModel;
use Doctrine\DBAL\Connection;

/**
 * A recorded provider call's writes, onto its run-log row and the run that `CallingRun` names. On DBAL on purpose:
 * they commit at once for the status poll, and never flush what the advancer's EntityManager holds dirty mid-tick.
 */
final readonly class RecommendationCallRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function recordStreamedChars(CallingRun $callingRun, int $streamedChars): void
    {
        $this->connection->update(
            $callingRun->table,
            ['streamed_chars' => $streamedChars],
            ['id' => $callingRun->id],
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
            ...self::outcomeColumns($settlement->outcome),
            ...self::receiptColumns($settlement->receipt),
        ], ['id' => $settlement->logId]);
    }

    /** Writes the failure's detail and keeps the checkpointed transcript; recordAdjustment() writes the adjustment. */
    public function settleTransportFailure(CallSettlement $settlement, CallFailure $failure): void
    {
        $this->connection->update('recommendation_run_log', [
            ...self::outcomeColumns($settlement->outcome),
            'error_detail' => $failure->getErrorDetail(),
            ...self::receiptColumns($settlement->receipt),
        ], ['id' => $settlement->logId]);
    }

    /** Onto the run's newest transport-failed call carrying the failure's detail, never a sibling's timeout. */
    public function recordAdjustment(CallingRun $callingRun, CallFailure $failure): void
    {
        $this->connection->executeStatement(
            'UPDATE recommendation_run_log SET adjusted_setting = :setting, adjusted_value = :value'
            . ' WHERE id = (SELECT newest.id FROM (SELECT MAX(id) AS id FROM recommendation_run_log'
            . ' WHERE ' . $callingRun->logOwnerColumn . ' = :runId AND verdict = :rejected'
            . ' AND error_detail = :rejection) AS newest)',
            [
                'setting' => $failure->getAdjustedSetting()?->value,
                'value' => $failure->getAdjustedValue(),
                'runId' => $callingRun->id,
                'rejected' => CallVerdict::TransportFailed->value,
                'rejection' => $failure->getErrorDetail(),
            ],
        );
    }

    /** SQL arithmetic, not read-modify-write: one batch wave settles several calls against the same run. */
    public function addUsage(CallingRun $callingRun, ProviderCallUsageModel $usage): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $callingRun->table . ' SET'
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
                'runId' => $callingRun->id,
            ],
        );

        $this->addCost($callingRun, $usage->costNanoCredits);
    }

    /** @return array<string, string|int|null> */
    private static function outcomeColumns(CallOutcome $outcome): array
    {
        return [
            'verdict' => $outcome->getVerdict()?->value,
            'wire_bytes' => $outcome->getWireBytes(),
            'finished_at' => $outcome->getFinishedAt()?->format('Y-m-d H:i:s'),
            'finish_reason' => $outcome->getFinishReason(),
        ];
    }

    /**
     * A streamed call settles without a receipt and writes none of its columns.
     *
     * @return array<string, string|int|null>
     */
    private static function receiptColumns(?CallReceipt $receipt): array
    {
        if (null === $receipt) {
            return [];
        }

        return [
            'request_id' => $receipt->getRequestId(),
            'answering_model' => $receipt->getAnsweringModel(),
            'cost_nano_credits' => $receipt->getCostNanoCredits(),
        ];
    }

    /** An unpriced call leaves the column NULL: null means no price was reported, 0 would claim the run was free. */
    private function addCost(CallingRun $callingRun, ?int $costNanoCredits): void
    {
        if (null === $costNanoCredits) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE ' . $callingRun->table
            . ' SET cost_nano_credits = COALESCE(cost_nano_credits, 0) + :costNanoCredits'
            . ' WHERE id = :runId',
            ['costNanoCredits' => $costNanoCredits, 'runId' => $callingRun->id],
        );
    }
}
