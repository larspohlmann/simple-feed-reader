<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

use App\Repository\RecommendationRunLogRepository;

/**
 * The newest profile run's calls, for the profile section's debug panel.
 *
 * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository
 */
final readonly class ProfileDebugLogModel
{
    /**
     * @param list<DebugLogRow>  $rows
     * @param array<int, string> $streamingTextById
     */
    public function __construct(public array $rows, public array $streamingTextById)
    {
    }

    public static function empty(): self
    {
        return new self([], []);
    }
}
