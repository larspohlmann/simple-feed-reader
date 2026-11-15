<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114170000 extends AbstractMigration
{
    private const string TABLE = 'feed';
    private const string COLUMN = 'language';

    public function getDescription(): string
    {
        return 'Store each feed\'s language for Trending\'s stemming (#2227).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable(self::TABLE)->hasColumn(self::COLUMN), 'Already applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN %s VARCHAR(2) DEFAULT NULL', self::TABLE, self::COLUMN));
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
