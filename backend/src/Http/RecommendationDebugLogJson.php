<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\Feed\Model\RecommendationDebugLogModel;

/**
 * Response shapes for the recommendation debug log (#309): poll-cheap, bodies never ride along, only sizes, except
 * the one call still streaming, whose growing text is the live view.
 *
 * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository
 */
final class RecommendationDebugLogJson
{
    /**
     * @return array{entries: list<array<string, mixed>>, run: ?array<string, mixed>,
     *     runs: list<array<string, mixed>>}
     */
    public static function list(RecommendationDebugLogModel $log): array
    {
        return [
            'entries' => array_map(
                static fn (array $row): array => [
                    ...self::entry($row),
                    'streamingText' => $log->streamingTextById[$row['id']] ?? null,
                ],
                $log->rows,
            ),
            'run' => null === $log->selectedRun ? null : self::run($log->selectedRun),
            'runs' => array_map(self::choice(...), $log->retainedRuns),
        ];
    }

    /**
     * @param DebugLogRow $row
     *
     * @return array<string, mixed>
     */
    private static function entry(array $row): array
    {
        return [
            ...$row,
            'phase' => $row['phase']->value,
            'verdict' => $row['verdict']?->value,
            'createdAt' => $row['createdAt']->format(\DATE_ATOM),
            'finishedAt' => $row['finishedAt']?->format(\DATE_ATOM),
        ];
    }

    /**
     * One entry of the run picker: enough to label it and no more. The
     * selected run's own counters ride in `run` instead.
     *
     * @return array<string, mixed>
     */
    private static function choice(RecommendationRun $run): array
    {
        return [
            'id' => $run->getId(),
            'status' => $run->getStatus()->value,
            'createdAt' => $run->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private static function run(RecommendationRun $run): array
    {
        return [
            'status' => $run->getStatus()->value,
            'error' => $run->getError(),
            'attempts' => $run->getAttempts(),
            'maxAttempts' => RecommendationRun::MAX_ATTEMPTS,
            'transportFailures' => $run->getTransportFailures(),
            'maxTransportFailures' => RecommendationRun::MAX_TRANSPORT_FAILURES,
            'createdAt' => $run->getCreatedAt()->format(\DATE_ATOM),
            'completedAt' => $run->getCompletedAt()?->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(RecommendationRunLog $log): array
    {
        return [
            'id' => $log->getId(),
            'phase' => $log->getPhase()->value,
            'batchNumber' => $log->getBatchNumber(),
            'attempt' => $log->getAttempt(),
            'verdict' => $log->getVerdict()?->value,
            'requestBody' => $log->getRequestBody(),
            'responseText' => $log->getResponseText(),
            'wireBytes' => $log->getWireBytes(),
            'finishReason' => $log->getFinishReason(),
        ];
    }

    private function __construct()
    {
    }
}
