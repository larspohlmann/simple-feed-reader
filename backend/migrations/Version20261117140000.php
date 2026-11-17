<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117140000 extends AbstractMigration
{
    private const string TABLE = 'social_notification';
    private const array COLUMNS = [
        'subject_context_answered_excerpt' => 500,
        'subject_context_thread_title' => 500,
        'subject_context_thread_place' => 255,
        'subject_context_thread_by' => 255,
    ];

    public function getDescription(): string
    {
        return 'Store what a notification answered and where it happened (#2296).';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $missing = array_filter(
            self::COLUMNS,
            static fn (int $length, string $column): bool => !$table->hasColumn($column),
            ARRAY_FILTER_USE_BOTH,
        );
        $this->skipIf([] === $missing, 'Already applied.');

        $this->abortIfUnsupportedPlatform();
        foreach ($missing as $column => $length) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN %s VARCHAR(%d) DEFAULT NULL', self::TABLE, $column, $length));
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $present = array_filter(
            array_keys(self::COLUMNS),
            static fn (string $column): bool => $table->hasColumn($column),
        );
        $this->skipIf([] === $present, 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        foreach ($present as $column) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', self::TABLE, $column));
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
