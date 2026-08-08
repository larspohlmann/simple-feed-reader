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
 * Opens the debug record for one provider call (#309): decides — per call,
 * so a mid-run settings flip takes effect on the next call — whether the
 * debug switch is on, persists the request body the moment it is sent, and
 * hands back the RecordedCall the advancer threads through the chat client
 * as its stream observer. With debug off the RecordedCall still exists,
 * because the liveness counter it maintains is not debug data.
 */
final readonly class RecommendationCallRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationRunLogRepository $logs,
        private Connection $connection,
        private RecommendationSettingsResolver $settingsResolver,
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
        $log = $this->settingsResolver->forUser($run->getUser())->debugEnabled
            ? $this->persistedLog($run, $phase, $batchNumber, $messages, $model)
            : null;

        return new RecordedCall(
            $this->connection,
            $this->clock,
            $run->getId() ?? throw new \LogicException('Cannot record a call for an unsaved run.'),
            $log?->getId(),
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
