<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;

/** Shared by the #2177 migrations that split user_preferences. */
trait SplitsUserPreferences
{
    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }

    private function abortIfHalfSplit(Schema $schema, string $table, string ...$columns): void
    {
        $columnsRemain = $schema->hasTable('user_preferences')
            && array_any($columns, $schema->getTable('user_preferences')->hasColumn(...));

        $this->abortIf(
            $schema->hasTable($table) && $columnsRemain,
            \sprintf(
                'Both %s and the matching user_preferences columns exist: an earlier run stopped part-way'
                . ' (DDL does not roll back). Repair by hand: decide which side holds the data, remove the'
                . ' other side (the table, or the partly added or dropped columns), then rerun.',
                $table,
            ),
        );
    }

    private function onMySql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    private function dropPreferenceColumns(string ...$columns): void
    {
        if ($this->onMySql()) {
            $this->addSql('ALTER TABLE user_preferences DROP ' . implode(', DROP ', $columns));

            return;
        }

        foreach ($columns as $column) {
            $this->addSql(\sprintf('ALTER TABLE user_preferences DROP COLUMN %s', $column));
        }
    }

    private function restoreColumn(string $table, string $column, string $settingsColumn, ?string $fallback): void
    {
        $copy = \sprintf(
            '(SELECT settings.%s FROM %s settings WHERE settings.user_id = user_preferences.user_id)',
            $settingsColumn,
            $table,
        );
        $this->addSql(\sprintf(
            'UPDATE user_preferences SET %s = %s',
            $column,
            null === $fallback ? $copy : \sprintf('COALESCE(%s, %s)', $copy, $fallback),
        ));
    }
}
