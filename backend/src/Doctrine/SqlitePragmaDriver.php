<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class SqlitePragmaDriver extends AbstractDriverMiddleware
{
    private const array SQLITE_DRIVERS = ['pdo_sqlite', 'sqlite3'];

    /**
     * What every SQLite connection needs before it is used.
     *
     * foreign_keys: SQLite ignores foreign key constraints unless a connection
     * asks for them, so without this a cascade never fires and a delete leaves
     * orphaned rows behind.
     *
     * journal_mode: the default (DELETE) locks readers out for the whole of a
     * write transaction. A refresh sweep writes for as long as it takes to
     * ingest every feed, and a reader hitting that window waits it out —
     * SQLite's busy timeout turns the error into a stall, which is worse to
     * diagnose than a failure. Write-ahead logging lets readers keep reading
     * during a write. It is a property of the database FILE, not of the
     * session, so re-applying it on each connect just reports the mode back.
     */
    private const array PRAGMAS = [
        'PRAGMA foreign_keys = ON',
        'PRAGMA journal_mode = WAL',
    ];

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $connection = parent::connect($params);

        if (!in_array($params['driver'] ?? null, self::SQLITE_DRIVERS, true)) {
            return $connection;
        }

        foreach (self::PRAGMAS as $pragma) {
            $connection->exec($pragma);
        }

        return $connection;
    }
}
