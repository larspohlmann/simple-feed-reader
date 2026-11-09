<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109120000 extends AbstractMigration
{
    private const array FAVICON_COLUMNS = [
        'favicon_source_url',
        'favicon_data',
        'favicon_content_type',
        'favicon_fetched_at',
        'favicon_failed_at',
    ];

    public function getDescription(): string
    {
        return 'Catalog feeds carry no favicon: the cached icons and their warm bookkeeping go (#2130).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('catalog_feed')->hasColumn('favicon_data'),
            'catalog_feed has no favicon columns.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE catalog_feed DROP ' . implode(', DROP ', self::FAVICON_COLUMNS));

            return;
        }

        $this->assertSqlite();
        foreach (self::FAVICON_COLUMNS as $column) {
            $this->addSql('ALTER TABLE catalog_feed DROP COLUMN ' . $column);
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('catalog_feed')->hasColumn('favicon_data'),
            'catalog_feed already has its favicon columns.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql(
                'ALTER TABLE catalog_feed ADD favicon_source_url VARCHAR(750) DEFAULT NULL, '
                . 'ADD favicon_data LONGBLOB DEFAULT NULL, ADD favicon_content_type VARCHAR(100) DEFAULT NULL, '
                . 'ADD favicon_fetched_at DATETIME DEFAULT NULL, ADD favicon_failed_at DATETIME DEFAULT NULL',
            );

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE catalog_feed ADD COLUMN favicon_source_url VARCHAR(750) DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_feed ADD COLUMN favicon_data BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_feed ADD COLUMN favicon_content_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_feed ADD COLUMN favicon_fetched_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_feed ADD COLUMN favicon_failed_at DATETIME DEFAULT NULL');
    }

    private function assertSqlite(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
