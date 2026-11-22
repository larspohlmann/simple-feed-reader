<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/** Records the SQL a call runs and picks out the shared, liked, kept and viewed history-section queries. */
trait RecordsHistorySectionQueries
{
    private const string HISTORY_SECTION_SQL = '/(shared_at|liked_at|voted_at|viewed_at) DESC|is_kept = \?/';

    private function startRecordingQueries(): void
    {
        $this->debugDataHolder()->reset();
    }

    /** @return list<string> */
    private function recordedHistorySectionQueries(): array
    {
        /** @var list<array{sql: string}> $statements */
        $statements = $this->debugDataHolder()->getData()['default'] ?? [];

        return array_values(array_filter(
            array_column($statements, 'sql'),
            static fn (string $sql): bool => 1 === preg_match(self::HISTORY_SECTION_SQL, $sql),
        ));
    }

    private function debugDataHolder(): DebugDataHolder
    {
        /** @var DebugDataHolder $queries */
        $queries = self::getContainer()->get('doctrine.debug_data_holder');

        return $queries;
    }
}
