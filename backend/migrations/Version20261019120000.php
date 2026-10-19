<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019120000 extends AbstractMigration
{
    private const string COLUMN = 'synced_kinds';

    public function getDescription(): string
    {
        return 'Add social_account.synced_kinds so a newly synced kind takes its own baseline (#1704)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('social_account')->hasColumn(self::COLUMN), 'synced_kinds already exists.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'ALTER TABLE social_account ADD synced_kinds JSON DEFAULT NULL'
            : 'ALTER TABLE social_account ADD COLUMN synced_kinds CLOB DEFAULT NULL');
        // Accounts #1666 already synced had a complete check of lists and feeds, never of followed tags.
        $this->addSql(
            'UPDATE social_account SET synced_kinds = \'["list","custom_feed"]\' WHERE last_synced_at IS NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('social_account')->hasColumn(self::COLUMN), 'synced_kinds does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('ALTER TABLE social_account DROP COLUMN ' . self::COLUMN);
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
