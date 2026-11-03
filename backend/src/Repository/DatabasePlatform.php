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
}
