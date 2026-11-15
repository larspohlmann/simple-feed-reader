<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The shared_link table and the three references to it, all nullable until Version20261115140100 fills them (#2239).
 * SQLite adds the columns in place, so entry keeps its FTS5 triggers.
 */
final class Version20261115140000 extends AbstractMigration
{
    private const string TABLE = 'shared_link';

    public function getDescription(): string
    {
        return 'Create shared_link and reference it from link_card, entry and trending_story_entry (#2239)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'shared_link already exists.');

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the shared_link migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'shared_link does not exist.');
        $this->skipIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'SQLite cannot drop a foreign key column without rebuilding entry; the columns and table stay.',
        );

        $this->addSql('ALTER TABLE trending_story_entry DROP FOREIGN KEY FK_8C8356658C730EC6');
        $this->addSql('ALTER TABLE trending_story_entry DROP INDEX IDX_8C8356658C730EC6, DROP shared_link_id');
        $this->addSql('ALTER TABLE link_card DROP FOREIGN KEY FK_A7530A7A8C730EC6');
        $this->addSql('ALTER TABLE link_card DROP INDEX IDX_A7530A7A8C730EC6, DROP shared_link_id');
        $this->addSql('ALTER TABLE entry DROP FOREIGN KEY FK_2B219D70156AD267');
        $this->addSql('ALTER TABLE entry DROP INDEX IDX_2B219D70156AD267, DROP quoted_shared_link_id');
        $this->addSql('DROP TABLE shared_link');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shared_link (
                id INT AUTO_INCREMENT NOT NULL,
                url VARCHAR(2048) NOT NULL,
                url_hash VARCHAR(64) NOT NULL,
                title VARCHAR(500) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                image_url VARCHAR(2048) DEFAULT NULL,
                site_name VARCHAR(255) NOT NULL,
                language VARCHAR(2) DEFAULT NULL,
                language_source VARCHAR(8) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_shared_link_url_hash (url_hash),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(
            'ALTER TABLE link_card ADD shared_link_id INT DEFAULT NULL,'
            . ' ADD INDEX IDX_A7530A7A8C730EC6 (shared_link_id),'
            . ' ADD CONSTRAINT FK_A7530A7A8C730EC6 FOREIGN KEY (shared_link_id) REFERENCES shared_link (id)',
        );
        $this->addSql(
            'ALTER TABLE trending_story_entry ADD shared_link_id INT DEFAULT NULL,'
            . ' ADD INDEX IDX_8C8356658C730EC6 (shared_link_id),'
            . ' ADD CONSTRAINT FK_8C8356658C730EC6 FOREIGN KEY (shared_link_id) REFERENCES shared_link (id)'
            . ' ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE entry ADD quoted_shared_link_id INT DEFAULT NULL,'
            . ' ADD INDEX IDX_2B219D70156AD267 (quoted_shared_link_id),'
            . ' ADD CONSTRAINT FK_2B219D70156AD267 FOREIGN KEY (quoted_shared_link_id) REFERENCES shared_link (id)',
        );
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shared_link (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                url VARCHAR(2048) NOT NULL,
                url_hash VARCHAR(64) NOT NULL,
                title VARCHAR(500) NOT NULL,
                description CLOB DEFAULT NULL,
                image_url VARCHAR(2048) DEFAULT NULL,
                site_name VARCHAR(255) NOT NULL,
                language VARCHAR(2) DEFAULT NULL,
                language_source VARCHAR(8) DEFAULT NULL,
                created_at DATETIME NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_shared_link_url_hash ON shared_link (url_hash)');
        $this->addSql(
            'ALTER TABLE link_card ADD COLUMN shared_link_id INTEGER DEFAULT NULL REFERENCES shared_link (id)',
        );
        $this->addSql('CREATE INDEX IDX_A7530A7A8C730EC6 ON link_card (shared_link_id)');
        $this->addSql(
            'ALTER TABLE trending_story_entry ADD COLUMN shared_link_id INTEGER DEFAULT NULL'
            . ' REFERENCES shared_link (id) ON DELETE SET NULL',
        );
        $this->addSql('CREATE INDEX IDX_8C8356658C730EC6 ON trending_story_entry (shared_link_id)');
        $this->addSql(
            'ALTER TABLE entry ADD COLUMN quoted_shared_link_id INTEGER DEFAULT NULL REFERENCES shared_link (id)',
        );
        $this->addSql('CREATE INDEX IDX_2B219D70156AD267 ON entry (quoted_shared_link_id)');
    }
}
