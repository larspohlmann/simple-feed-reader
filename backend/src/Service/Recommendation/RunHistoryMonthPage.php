<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\RecommendationRunHistoryRepository;

/**
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
 */
final readonly class RunHistoryMonthPage
{
    /** @param list<HistoryRow> $rows already truncated to the page size */
    public function __construct(
        public string $month,
        public array $rows,
        public ?int $nextCursor,
    ) {
    }
}
