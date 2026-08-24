<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saved searches (#581): a per-user stored search term plus a whole-word flag.
 *
 * Hand-written and platform-aware. The test suite builds its schema from ORM
 * metadata and never runs this file; only CI's migrate-from-empty leg does, on
 * both SQLite and MySQL. A raw diff on a SQLite dev box would emit SQLite-only
 * DDL, so keep both branches.
 *
 * The FK/index names below (FK_D0F6A0BCA76ED395, IDX_D0F6A0BCA76ED395) and the
 * `app_user` table name come from a `doctrine:migrations:diff` run against ORM
 * metadata, not a guess: the user table in this app is `app_user`, not `user`.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create saved_search (per-user saved searches, #581).';
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
        $this->abortIf(!$mysql && !$sqlite, 'Unsupported platform for this migration.');

        if ($schema->hasTable('saved_search')) {
            return;
        }

        if ($mysql) {
            $this->addSql(<<<'SQL'
                CREATE TABLE saved_search (
                    id INT AUTO_INCREMENT NOT NULL,
                    user_id INT NOT NULL,
                    term VARCHAR(100) NOT NULL,
                    whole_word TINYINT(1) DEFAULT 0 NOT NULL,
                    position INT DEFAULT 0 NOT NULL,
                    UNIQUE INDEX uniq_saved_search_user_term_word (user_id, term, whole_word),
                    INDEX IDX_D0F6A0BCA76ED395 (user_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql(<<<'SQL'
                ALTER TABLE saved_search
                ADD CONSTRAINT FK_D0F6A0BCA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
                SQL);

            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE saved_search (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NOT NULL,
                term VARCHAR(100) NOT NULL,
                whole_word BOOLEAN DEFAULT 0 NOT NULL,
                position INTEGER DEFAULT 0 NOT NULL,
                CONSTRAINT FK_D0F6A0BCA76ED395 FOREIGN KEY (user_id)
                    REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_saved_search_user_term_word ON saved_search (user_id, term, whole_word)');
        $this->addSql('CREATE INDEX IDX_D0F6A0BCA76ED395 ON saved_search (user_id)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('saved_search')) {
            return;
        }
        $this->addSql('DROP TABLE saved_search');
    }
}
