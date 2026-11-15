<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120000 extends AbstractMigration
{
    private const string TABLE = 'trending_story_entry';
    private const string COLUMN = 'article_site';

    public function getDescription(): string
    {
        return 'Store the site of the article each Trending story member is or shares (#2229).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable(self::TABLE)->hasColumn(self::COLUMN), 'Already applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN %s VARCHAR(255) DEFAULT NULL', self::TABLE, self::COLUMN));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable(self::TABLE)->hasColumn(self::COLUMN), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', self::TABLE, self::COLUMN));
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
