<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Connection;

/** Multi-row INSERTs, a chunk of rows per statement; every row binds one value per column of the statement head. */
final readonly class BulkInsert
{
    /** @param positive-int $rowsPerStatement */
    private function __construct(private string $insertInto, private int $rowsPerStatement)
    {
    }

    /**
     * @param string $insertInto the statement up to VALUES, e.g. "INSERT INTO tag (name, user_id)"
     * @param positive-int $rowsPerStatement
     */
    public static function into(string $insertInto, int $rowsPerStatement): self
    {
        return new self($insertInto, $rowsPerStatement);
    }

    /**
     * @param list<list<int|string|null>> $rows
     *
     * @return int the rows the database reports as written
     */
    public function rows(Connection $connection, array $rows): int
    {
        $written = 0;
        foreach (array_chunk($rows, $this->rowsPerStatement) as $chunk) {
            $rowPlaceholders = '(' . implode(', ', array_fill(0, \count($chunk[0]), '?')) . ')';
            $written += (int) $connection->executeStatement(
                $this->insertInto . ' VALUES ' . implode(', ', array_fill(0, \count($chunk), $rowPlaceholders)),
                array_merge(...$chunk),
            );
        }

        return $written;
    }
}
