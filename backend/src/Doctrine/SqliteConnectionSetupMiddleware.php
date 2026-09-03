<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

// Priority above DAMA's 100 so the pragmas run on the raw connection before any
// wrapping transaction starts — both of them are no-ops inside a transaction.
#[AsMiddleware(priority: 150)]
final class SqliteConnectionSetupMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SqliteConnectionSetupDriver($driver);
    }
}
