<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Platform-aware DDL, as Version20261016120000: the two dialects' auto-increment keys and column adds differ. */
final class Version20261017160000 extends AbstractMigration
{
    private const string TABLE = 'thread_post';

    public function getDescription(): string
    {
        return 'Create thread_post and link_card.thread_post_id for self-reply threads (#1668)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'thread_post already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        throw new \RuntimeException('Unsupported database platform for thread_post migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'thread_post does not exist.');

        $platform = $this->connection->getDatabasePlatform();
        $this->skipIf(
            !$platform instanceof AbstractMySQLPlatform,
            'SQLite cannot drop the link_card.thread_post_id foreign key; the column stays.',
        );

        $this->addSql('ALTER TABLE link_card DROP FOREIGN KEY FK_A7530A7AFA33EB65');
        $this->addSql('DROP INDEX IDX_A7530A7AFA33EB65 ON link_card');
        $this->addSql('ALTER TABLE link_card DROP COLUMN thread_post_id');
        $this->addSql('DROP TABLE thread_post');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE thread_post (
                id INT AUTO_INCREMENT NOT NULL,
                entry_id INT NOT NULL,
                position SMALLINT NOT NULL,
                url VARCHAR(2048) DEFAULT NULL,
                published_at DATETIME DEFAULT NULL,
                content_html LONGTEXT NOT NULL,
                media JSON DEFAULT NULL,
                INDEX IDX_DE3B7003BA364942 (entry_id),
                UNIQUE INDEX uniq_thread_post_entry_position (entry_id, position),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(
            'ALTER TABLE thread_post ADD CONSTRAINT FK_DE3B7003BA364942 FOREIGN KEY (entry_id) '
            . 'REFERENCES entry (id) ON DELETE CASCADE',
        );
        $this->addSql('ALTER TABLE link_card ADD thread_post_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE link_card ADD CONSTRAINT FK_A7530A7AFA33EB65 FOREIGN KEY (thread_post_id) '
            . 'REFERENCES thread_post (id) ON DELETE CASCADE',
        );
        $this->addSql('CREATE INDEX IDX_A7530A7AFA33EB65 ON link_card (thread_post_id)');
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE thread_post (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                entry_id INTEGER NOT NULL,
                position SMALLINT NOT NULL,
                url VARCHAR(2048) DEFAULT NULL,
                published_at DATETIME DEFAULT NULL,
                content_html CLOB NOT NULL,
                media CLOB DEFAULT NULL,
                CONSTRAINT FK_DE3B7003BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_DE3B7003BA364942 ON thread_post (entry_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_thread_post_entry_position ON thread_post (entry_id, position)');
        $this->addSql(
            'ALTER TABLE link_card ADD COLUMN thread_post_id INTEGER DEFAULT NULL '
            . 'REFERENCES thread_post (id) ON DELETE CASCADE',
        );
        $this->addSql('CREATE INDEX IDX_A7530A7AFA33EB65 ON link_card (thread_post_id)');
    }
}
