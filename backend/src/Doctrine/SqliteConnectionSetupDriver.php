<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class SqliteConnectionSetupDriver extends AbstractDriverMiddleware
{
    private const array SQLITE_DRIVERS = ['pdo_sqlite', 'sqlite3'];

    /**
     * foreign_keys: SQLite ignores FK constraints, and so every cascade, unless asked. journal_mode=WAL: the default
     * locks readers out for a whole write transaction, and a refresh sweep writes for as long as ingesting takes.
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
        $this->registerSearchFunctions($connection);

        return $connection;
    }

    /**
     * NORMALIZE_WORD_BOUNDARIES is one native call because the nested-REPLACE rendering overflows the parser stack of
     * SQLite before 3.46 (#584). It folds case too, as SQLite's LIKE folds ASCII alone, so a whole word costs one call.
     */
    private function registerSearchFunctions(Connection $connection): void
    {
        $nativeConnection = $connection->getNativeConnection();
        if (!$nativeConnection instanceof \PDO) {
            return;
        }

        $functions = [
            NormalizeWordBoundariesFunction::NAME => static fn (string $value): string => CaseFolding::fold(
                WordBoundaries::normalize($value),
            ),
            FoldCaseFunction::NAME => CaseFolding::fold(...),
        ];
        foreach ($functions as $name => $function) {
            $nativeConnection->sqliteCreateFunction(
                $name,
                static fn (?string $value): ?string => $value === null ? null : $function($value),
                1,
            );
        }
    }
}
