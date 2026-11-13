<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113110000 extends AbstractMigration
{
    private const array COLUMNS = ['include_in_all_items', 'include_in_for_you', 'include_in_saved_searches'];

    public function getDescription(): string
    {
        return 'Let a tag switch its feeds out of All items, For You and saved searches (#2202).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('tag')->hasColumn(self::COLUMNS[0]), 'Already applied.');

        $this->abortIfUnsupportedPlatform();
        $type = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'TINYINT(1)' : 'BOOLEAN';
        foreach (self::COLUMNS as $column) {
            $this->addSql(\sprintf('ALTER TABLE tag ADD COLUMN %s %s DEFAULT 1 NOT NULL', $column, $type));
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('tag')->hasColumn(self::COLUMNS[0]), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        foreach (self::COLUMNS as $column) {
            $this->addSql('ALTER TABLE tag DROP COLUMN ' . $column);
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
