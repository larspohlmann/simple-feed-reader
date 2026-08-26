<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\RecommendationRunLogRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Opens the run-log record for one provider call (#309, #638): persists the
 * request body the moment it is sent and hands back the RecordedCall the
 * advancer threads through the chat client as its stream observer. Recorded
 * for every run — the run log is the phase-timing history the ETA reads
 * (#638), and the debug switch now only governs whether the panel shows it.
 */
final readonly class RecommendationCallRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationRunLogRepository $logs,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<array{role: string, content: string}> $messages */
    public function begin(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        array $messages,
        string $model,
    ): RecordedCall {
        $log = $this->persistedLog($run, $phase, $batchNumber, $messages, $model);

        return new RecordedCall(
            $this->connection,
            $this->clock,
            $run->getId() ?? throw new \LogicException('Cannot record a call for an unsaved run.'),
            $log->getId(),
        );
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function persistedLog(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        array $messages,
        string $model,
    ): RecommendationRunLog {
        $log = new RecommendationRunLog(
            $run,
            $phase,
            $batchNumber,
            $this->nextAttempt($run, $phase, $batchNumber),
            $this->renderedRequest($messages, $model),
            $this->clock->now(),
        );
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    /**
     * Attempts are derived from what is already recorded rather than passed
     * in, so the recorder cannot disagree with its own rows.
     */
    private function nextAttempt(RecommendationRun $run, string $phase, ?int $batchNumber): int
    {
        return $this->logs->countAttempts($run, $phase, $batchNumber) + 1;
    }

    /**
     * Pretty-printed for the human the debug view exists for; this is the
     * payload as sent, minus transport framing.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    private function renderedRequest(array $messages, string $model): string
    {
        return json_encode(
            ['model' => $model, 'messages' => $messages],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }
}
