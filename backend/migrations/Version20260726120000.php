<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the onboarding catalog tables. DDL ONLY — no seed rows.
 *
 * The catalog is data, not schema: it ships as resources/catalog/catalog.opml
 * and an admin imports it. That keeps catalog changes out of the migration
 * chain entirely, so a corrected feed URL is an import rather than a new
 * migration, and rows an admin has edited are never rewritten by a deploy.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260724120000 is: tests build
 * their schema from ORM metadata, so a dialect error here would not be caught
 * by the suite — only by CI's dedicated migration leg.
 */
final class Version20260726120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog_category and catalog_feed (no data; the catalog is imported)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        // Already baselined from ORM metadata: nothing to create.
        if ($schema->hasTable('catalog_category')) {
            return;
        }

        if ($mysql) {
            $this->addSql(<<<'SQL'
                CREATE TABLE catalog_category (
                    id INT AUTO_INCREMENT NOT NULL,
                    category_key VARCHAR(64) NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    icon VARCHAR(64) NOT NULL,
                    color VARCHAR(7) NOT NULL,
                    position INT DEFAULT 0 NOT NULL,
                    enabled TINYINT(1) DEFAULT 1 NOT NULL,
                    locked TINYINT(1) DEFAULT 0 NOT NULL,
                    UNIQUE INDEX uniq_catalog_category_key (category_key),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql(<<<'SQL'
                CREATE TABLE catalog_feed (
                    id INT AUTO_INCREMENT NOT NULL,
                    category_id INT NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    url VARCHAR(750) NOT NULL,
                    site_url VARCHAR(750) DEFAULT NULL,
                    description VARCHAR(255) DEFAULT NULL,
                    source_format VARCHAR(20) DEFAULT 'xml' NOT NULL,
                    position INT DEFAULT 0 NOT NULL,
                    enabled TINYINT(1) DEFAULT 1 NOT NULL,
                    locked TINYINT(1) DEFAULT 0 NOT NULL,
                    favicon_source_url VARCHAR(750) DEFAULT NULL,
                    favicon_data LONGBLOB DEFAULT NULL,
                    favicon_content_type VARCHAR(100) DEFAULT NULL,
                    favicon_fetched_at DATETIME DEFAULT NULL,
                    favicon_failed_at DATETIME DEFAULT NULL,
                    UNIQUE INDEX uniq_catalog_feed_url (url),
                    INDEX IDX_56F5F4DD12469DE2 (category_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql('ALTER TABLE catalog_feed ADD CONSTRAINT fk_catalog_feed_category FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE');
        }

        if ($sqlite) {
            $this->addSql(<<<'SQL'
                CREATE TABLE catalog_category (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    category_key VARCHAR(64) NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    icon VARCHAR(64) NOT NULL,
                    color VARCHAR(7) NOT NULL,
                    position INTEGER DEFAULT 0 NOT NULL,
                    enabled BOOLEAN DEFAULT 1 NOT NULL,
                    locked BOOLEAN DEFAULT 0 NOT NULL
                )
                SQL);
            $this->addSql('CREATE UNIQUE INDEX uniq_catalog_category_key ON catalog_category (category_key)');
            $this->addSql(<<<'SQL'
                CREATE TABLE catalog_feed (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    category_id INTEGER NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    url VARCHAR(750) NOT NULL,
                    site_url VARCHAR(750) DEFAULT NULL,
                    description VARCHAR(255) DEFAULT NULL,
                    source_format VARCHAR(20) DEFAULT 'xml' NOT NULL,
                    position INTEGER DEFAULT 0 NOT NULL,
                    enabled BOOLEAN DEFAULT 1 NOT NULL,
                    locked BOOLEAN DEFAULT 0 NOT NULL,
                    favicon_source_url VARCHAR(750) DEFAULT NULL,
                    favicon_data BLOB DEFAULT NULL,
                    favicon_content_type VARCHAR(100) DEFAULT NULL,
                    favicon_fetched_at DATETIME DEFAULT NULL,
                    favicon_failed_at DATETIME DEFAULT NULL,
                    CONSTRAINT fk_catalog_feed_category FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE
                )
                SQL);
            $this->addSql('CREATE UNIQUE INDEX uniq_catalog_feed_url ON catalog_feed (url)');
            $this->addSql('CREATE INDEX IDX_56F5F4DD12469DE2 ON catalog_feed (category_id)');
        }
    }

    public function down(Schema $schema): void
    {
        // catalog_feed first, because of the FK.
        $this->addSql('DROP TABLE IF EXISTS catalog_feed');
        $this->addSql('DROP TABLE IF EXISTS catalog_category');
    }
}
