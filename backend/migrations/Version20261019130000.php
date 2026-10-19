<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019130000 extends AbstractMigration
{
    private const string COLUMN = 'last_synced_at';

    public function getDescription(): string
    {
        return 'Drop social_account.last_synced_at, which synced_kinds replaced (#1705)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('social_account')->hasColumn(self::COLUMN), 'last_synced_at does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('ALTER TABLE social_account DROP COLUMN ' . self::COLUMN);
    }

    public function down(Schema $schema): void
    {
        $this->skipIf($schema->getTable('social_account')->hasColumn(self::COLUMN), 'last_synced_at already exists.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'ALTER TABLE social_account ADD last_synced_at DATETIME DEFAULT NULL'
            : 'ALTER TABLE social_account ADD COLUMN last_synced_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE social_account SET last_synced_at = last_checked_at WHERE synced_kinds IS NOT NULL');
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
