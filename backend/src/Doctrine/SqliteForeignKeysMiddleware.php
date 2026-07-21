<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

// Priority above DAMA's 100 so the pragma runs on the raw connection before any
// wrapping transaction starts — PRAGMA foreign_keys is a no-op inside a transaction.
#[AsMiddleware(priority: 150)]
final class SqliteForeignKeysMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SqliteForeignKeysDriver($driver);
    }
}
