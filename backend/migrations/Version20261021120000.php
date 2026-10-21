<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    private const string COLUMN = 'platform';

    public function getDescription(): string
    {
        return 'Add feed.platform, the platform a feed is published on (#1737)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('feed')->hasColumn(self::COLUMN), 'feed.platform already exists.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'ALTER TABLE feed ADD platform VARCHAR(16) DEFAULT NULL'
            : 'ALTER TABLE feed ADD COLUMN platform VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('feed')->hasColumn(self::COLUMN), 'feed.platform does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('ALTER TABLE feed DROP COLUMN ' . self::COLUMN);
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
