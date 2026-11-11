<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/** The #2177 migrations that split user_preferences; a split one names its settings table in TABLE. */
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

    private function restoreColumn(string $column, string $settingsColumn, ?string $fallback): void
    {
        $copy = \sprintf(
            '(SELECT settings.%s FROM %s settings WHERE settings.user_id = user_preferences.user_id)',
            $settingsColumn,
            self::TABLE,
        );
        $this->addSql(\sprintf(
            'UPDATE user_preferences SET %s = %s',
            $column,
            null === $fallback ? $copy : \sprintf('COALESCE(%s, %s)', $copy, $fallback),
        ));
    }
}
