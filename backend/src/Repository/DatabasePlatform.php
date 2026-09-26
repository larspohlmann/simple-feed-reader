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
}
