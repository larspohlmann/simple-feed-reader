<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create category and entry_category (#953): the feed-declared per-entry
 * categories, normalized. PLATFORM-AWARE DDL — SQLite's INTEGER PRIMARY KEY
 * AUTOINCREMENT is not valid MySQL. Tests build schema from ORM metadata and
 * never run a migration, so a dialect error here is caught only by CI's
 * migrate-from-empty leg.
 */
final class Version20260913180658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create category and entry_category for feed-declared entry categories (#953)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('category'), 'category already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE category (
                    id INT AUTO_INCREMENT NOT NULL,
                    canonical_key VARCHAR(128) NOT NULL,
                    scheme VARCHAR(255) DEFAULT '' NOT NULL,
                    UNIQUE INDEX uniq_category_key_scheme (canonical_key, scheme),
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql(<<<'SQL'
                CREATE TABLE entry_category (
                    entry_id INT NOT NULL,
                    category_id INT NOT NULL,
                    position SMALLINT NOT NULL,
                    label VARCHAR(128) NOT NULL,
                    INDEX IDX_680BF989BA364942 (entry_id),
                    INDEX IDX_680BF98912469DE2 (category_id),
                    PRIMARY KEY (entry_id, category_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql('ALTER TABLE entry_category ADD CONSTRAINT FK_680BF989BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE entry_category ADD CONSTRAINT FK_680BF98912469DE2 FOREIGN KEY (category_id) REFERENCES category (id)');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE category (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    canonical_key VARCHAR(128) NOT NULL,
                    scheme VARCHAR(255) DEFAULT '' NOT NULL
                )
                SQL);
            $this->addSql('CREATE UNIQUE INDEX uniq_category_key_scheme ON category (canonical_key, scheme)');
            $this->addSql(<<<'SQL'
                CREATE TABLE entry_category (
                    position SMALLINT NOT NULL,
                    label VARCHAR(128) NOT NULL,
                    entry_id INTEGER NOT NULL,
                    category_id INTEGER NOT NULL,
                    PRIMARY KEY (entry_id, category_id),
                    CONSTRAINT FK_680BF989BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                    CONSTRAINT FK_680BF98912469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE
                )
                SQL);
            $this->addSql('CREATE INDEX IDX_680BF989BA364942 ON entry_category (entry_id)');
            $this->addSql('CREATE INDEX IDX_680BF98912469DE2 ON entry_category (category_id)');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the entry categories migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('entry_category'), 'entry_category does not exist.');
        $this->addSql('DROP TABLE entry_category');
        $this->addSql('DROP TABLE category');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
