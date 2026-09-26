<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final readonly class DatabaseHealthRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function ping(): void
    {
        $this->connection->executeQuery('SELECT 1');
    }
}
