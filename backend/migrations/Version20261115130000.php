<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115130000 extends AbstractMigration
{
    private const string TABLE = 'trending_story';
    private const string COLUMN = 'outlet_count';
    private const string OLD_INDEX = 'idx_trending_story_window_outlets';
    private const string NEW_INDEX = 'idx_trending_story_window';

    public function getDescription(): string
    {
        return 'Drop the instance-wide outlet count of a Trending story: a reader only ever sees their own (#2229).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable(self::TABLE)->hasColumn(self::COLUMN), 'Already applied.');

        $this->dropIndex(self::OLD_INDEX);
        $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', self::TABLE, self::COLUMN));
        $this->addSql(\sprintf('CREATE INDEX %s ON %s (time_window)', self::NEW_INDEX, self::TABLE));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf($schema->getTable(self::TABLE)->hasColumn(self::COLUMN), 'Not applied.');

        $this->dropIndex(self::NEW_INDEX);
        $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN %s INT NOT NULL DEFAULT 0', self::TABLE, self::COLUMN));
        $this->addSql(
            \sprintf('CREATE INDEX %s ON %s (time_window, %s)', self::OLD_INDEX, self::TABLE, self::COLUMN),
        );
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function dropIndex(string $index): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
        $this->addSql($platform instanceof AbstractMySQLPlatform
            ? \sprintf('DROP INDEX %s ON %s', $index, self::TABLE)
            : \sprintf('DROP INDEX %s', $index));
    }
}
