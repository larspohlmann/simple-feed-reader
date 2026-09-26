<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;

/**
 * @phpstan-type DebugLogRow array{id: int, runId: int, phase: string, batchNumber: ?int, attempt: int,
 *     verdict: ?string, requestBytes: int, responseBytes: int, wireBytes: int,
 *     createdAt: string, finishedAt: ?string, errorDetail: ?string, finishReason: ?string}
 */
final readonly class RecommendationDebugLog
{
    /**
     * @param list<DebugLogRow>       $rows
     * @param array<int, string>      $streamingTextById
     * @param list<RecommendationRun> $retainedRuns newest first, the runs the panel may switch to
     */
    public function __construct(
        public array $rows,
        public array $streamingTextById,
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public ?RecommendationRun $selectedRun,
        public array $retainedRuns,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], null, []);
    }
}
