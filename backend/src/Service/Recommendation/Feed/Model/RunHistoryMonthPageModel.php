<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed\Model;

use App\Repository\RecommendationRunHistoryRepository;

/**
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
 */
final readonly class RunHistoryMonthPageModel
{
    /** @param list<HistoryRow> $rows already truncated to the page size */
    public function __construct(
        public string $month,
        public array $rows,
        public ?int $nextCursor,
    ) {
    }
}
