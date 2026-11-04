<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Connection;

/** Multi-row INSERTs, a chunk of rows per statement; every row binds one value per column of the statement head. */
final readonly class BulkInsert
{
    /**
     * @param string $insertInto the statement up to VALUES, e.g. "INSERT INTO tag (name, user_id)"
     * @param list<list<int|string|null>> $rows
     * @param positive-int $rowsPerStatement
     *
     * @return int the rows the database reports as written
     */
    public static function rows(Connection $connection, string $insertInto, array $rows, int $rowsPerStatement): int
    {
        $written = 0;
        foreach (array_chunk($rows, $rowsPerStatement) as $chunk) {
            $rowPlaceholders = '(' . implode(', ', array_fill(0, \count($chunk[0]), '?')) . ')';
            $written += (int) $connection->executeStatement(
                $insertInto . ' VALUES ' . implode(', ', array_fill(0, \count($chunk), $rowPlaceholders)),
                array_merge(...$chunk),
            );
        }

        return $written;
    }

    private function __construct()
    {
    }
}
