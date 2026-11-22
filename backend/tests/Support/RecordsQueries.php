<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/** Records the SQL the default connection runs from startRecordingQueries() on. */
trait RecordsQueries
{
    private function startRecordingQueries(): void
    {
        $this->debugDataHolder()->reset();
    }

    /** @return list<string> */
    private function recordedQueries(): array
    {
        /** @var list<array{sql: string}> $statements */
        $statements = $this->debugDataHolder()->getData()['default'] ?? [];

        return array_column($statements, 'sql');
    }

    /** @return list<string> */
    private function recordedQueriesMatching(string $pattern): array
    {
        return array_values(array_filter(
            $this->recordedQueries(),
            static fn (string $sql): bool => 1 === preg_match($pattern, $sql),
        ));
    }

    private function debugDataHolder(): DebugDataHolder
    {
        /** @var DebugDataHolder $queries */
        $queries = self::getContainer()->get('doctrine.debug_data_holder');

        return $queries;
    }
}
