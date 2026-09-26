<?php

declare(strict_types=1);

namespace App\Service\Reading;

final readonly class ReadingActivity
{
    /**
     * @param list<string>                             $localDates     oldest first, one 'Y-m-d' per day
     * @param array<string, int>                       $countsByDay    local 'Y-m-d' => articles opened
     * @param list<array{feedId: int, readCount: int}> $topFeedsByRead busiest feed first
     */
    public function __construct(
        public array $localDates,
        public array $countsByDay,
        public array $topFeedsByRead,
    ) {
    }
}
