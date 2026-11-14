<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114120000 extends AbstractMigration
{
    private const string TABLE = 'discussion_vote';

    public function getDescription(): string
    {
        return 'A reader\'s vote on a discussion post: discussion_vote (#2218).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->upOnMySql();

            return;
        }

        $this->upOnSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE ' . self::TABLE);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upOnMySql(): void
    {
        $this->addSql('CREATE TABLE discussion_vote (id INT AUTO_INCREMENT NOT NULL, score SMALLINT NOT NULL,'
            . ' voted_at DATETIME NOT NULL, user_id INT NOT NULL, entry_id INT NOT NULL,'
            . ' social_account_id INT NOT NULL, INDEX IDX_7F7E2952A76ED395 (user_id),'
            . ' INDEX idx_discussion_vote_entry (entry_id), INDEX idx_discussion_vote_account (social_account_id),'
            . ' UNIQUE INDEX uniq_discussion_vote_user_entry (user_id, entry_id), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE discussion_vote ADD CONSTRAINT FK_7F7E2952A76ED395'
            . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discussion_vote ADD CONSTRAINT FK_7F7E2952BA364942'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discussion_vote ADD CONSTRAINT FK_7F7E29525538ED78'
            . ' FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE');
    }

    private function upOnSqlite(): void
    {
        $this->addSql('CREATE TABLE discussion_vote (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' score SMALLINT NOT NULL, voted_at DATETIME NOT NULL, user_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_7F7E2952A76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_7F7E2952BA364942 FOREIGN KEY (entry_id)'
            . ' REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_7F7E29525538ED78 FOREIGN KEY (social_account_id)'
            . ' REFERENCES social_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7F7E2952A76ED395 ON discussion_vote (user_id)');
        $this->addSql('CREATE INDEX idx_discussion_vote_entry ON discussion_vote (entry_id)');
        $this->addSql('CREATE INDEX idx_discussion_vote_account ON discussion_vote (social_account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_discussion_vote_user_entry ON discussion_vote (user_id, entry_id)');
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
