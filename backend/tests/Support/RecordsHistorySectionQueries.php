<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Tells the favorites history query apart from the shared, liked, kept and viewed ones in the recorded SQL. */
trait RecordsHistorySectionQueries
{
    use RecordsQueries;

    private const string FAVORITES_SQL = '/is_favorite = \? ORDER BY \w+\.effective_date DESC/';
    private const string HISTORY_SECTION_SQL
        = '/(shared_at|liked_at|voted_at|viewed_at) DESC|is_kept = \? AND \w+\.is_favorite = \?/';

    /** @return list<string> */
    private function recordedHistorySectionQueries(): array
    {
        return $this->recordedQueriesMatching(self::HISTORY_SECTION_SQL);
    }

    private function assertReadFavoritesAloneFromTheHistory(): void
    {
        self::assertCount(1, $this->recordedQueriesMatching(self::FAVORITES_SQL));
        self::assertSame([], $this->recordedHistorySectionQueries());
    }
}
