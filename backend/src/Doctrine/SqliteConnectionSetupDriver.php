<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Service\Search\WordBoundaries;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class SqliteConnectionSetupDriver extends AbstractDriverMiddleware
{
    private const array SQLITE_DRIVERS = ['pdo_sqlite', 'sqlite3'];

    /**
     * What every SQLite connection needs before use: these pragmas, plus the
     * word-boundary function (see registerWordBoundariesFunction).
     *
     * foreign_keys: SQLite ignores FK constraints unless asked, so without this
     * a cascade never fires and deletes leave orphaned rows.
     *
     * journal_mode: the default (DELETE) locks readers out for a whole write
     * transaction; a refresh sweep writes for as long as it takes to ingest
     * every feed, and SQLite's busy timeout turns that wait into a stall
     * (worse to diagnose than a failure). WAL lets readers read during a
     * write. It's a property of the file, not the session, so re-applying it
     * each connect just reports the mode back.
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
        $this->registerWordBoundariesFunction($connection);

        return $connection;
    }

    /**
     * NORMALIZE_WORD_BOUNDARIES as one native call on SQLite. Spelled out as
     * nested REPLACEs (the MySQL rendering) it is 26 levels deep, and SQLite
     * before 3.46 parses with a fixed 100-entry stack: one whole-word search
     * fits, two ORed together overflow it (#584). PHP's own normalize() is the
     * rule, so the two engines cannot drift.
     */
    private function registerWordBoundariesFunction(Connection $connection): void
    {
        $normalize = static fn (?string $value): ?string => $value === null ? null : WordBoundaries::normalize($value);
        $nativeConnection = $connection->getNativeConnection();

        if ($nativeConnection instanceof \PDO) {
            $nativeConnection->sqliteCreateFunction(NormalizeWordBoundariesFunction::NAME, $normalize, 1);
        }
    }
}
