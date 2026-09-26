<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunLogRepository;

/**
 * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository
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
