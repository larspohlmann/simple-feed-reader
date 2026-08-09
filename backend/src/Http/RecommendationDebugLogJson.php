<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;

/**
 * Response shapes for the recommendation debug log (#309). The list shape is
 * poll-cheap by construction: bodies never ride along, only sizes — except
 * the one call still streaming, whose growing text IS the live view.
 */
final class RecommendationDebugLogJson
{
    /**
     * @param list<array{id: int, phase: string, batchNumber: ?int, attempt: int,
     *     verdict: ?string, requestBytes: int, responseBytes: int, wireBytes: int,
     *     createdAt: string, finishedAt: ?string, errorDetail: ?string}> $rows
     * @param array<int, string> $streamingTextById
     *
     * @return array{entries: list<array<string, mixed>>, run: ?array<string, mixed>}
     */
    public static function list(array $rows, array $streamingTextById, ?RecommendationRun $run): array
    {
        return [
            'entries' => array_map(
                static fn (array $row): array => [...$row, 'streamingText' => $streamingTextById[$row['id']] ?? null],
                $rows,
            ),
            'run' => null === $run ? null : self::run($run),
        ];
    }

    /** @return array<string, mixed> */
    private static function run(RecommendationRun $run): array
    {
        return [
            'status' => $run->getStatus(),
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
            'phase' => $log->getPhase(),
            'batchNumber' => $log->getBatchNumber(),
            'attempt' => $log->getAttempt(),
            'verdict' => $log->getVerdict(),
            'requestBody' => $log->getRequestBody(),
            'responseText' => $log->getResponseText(),
            'wireBytes' => $log->getWireBytes(),
        ];
    }

    private function __construct()
    {
    }
}
