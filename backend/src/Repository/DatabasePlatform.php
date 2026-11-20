<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/** The MySQL/SQLite dialect switch shared by every repository that upserts with a database-specific clause. */
final readonly class DatabasePlatform
{
    public static function isMySql(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    /** The keyword that turns an INSERT into one that skips rows already stored. */
    public static function insertIgnoreClause(Connection $connection): string
    {
        return self::isMySql($connection) ? 'IGNORE' : 'OR IGNORE';
    }

    /**
     * The clause that makes an INSERT overwrite the listed columns of a row that already holds the same key.
     *
     * @param non-empty-list<string> $conflictColumns the columns of the unique key
     * @param non-empty-list<string> $updateColumns   the columns the incoming row overwrites
     */
    public static function buildUpsertClause(
        Connection $connection,
        array $conflictColumns,
        array $updateColumns,
    ): string {
        if (self::isMySql($connection)) {
            return 'ON DUPLICATE KEY UPDATE ' . self::buildAssignments($updateColumns, 'VALUES(%s)');
        }

        return 'ON CONFLICT (' . implode(', ', $conflictColumns) . ') DO UPDATE SET '
            . self::buildAssignments($updateColumns, 'excluded.%s');
    }

    /** @param non-empty-list<string> $columns */
    private static function buildAssignments(array $columns, string $incomingFormat): string
    {
        return implode(', ', array_map(
            static fn (string $column): string => $column . ' = ' . sprintf($incomingFormat, $column),
            $columns,
        ));
    }
}
