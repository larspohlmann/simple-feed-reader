<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class SqliteForeignKeysDriver extends AbstractDriverMiddleware
{
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $connection = parent::connect($params);

        if (in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true)) {
            $connection->exec('PRAGMA foreign_keys = ON');
        }

        return $connection;
    }
}
