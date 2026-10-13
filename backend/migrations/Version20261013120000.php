<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create social_like: a user\'s likes of Bluesky and Mastodon posts at their origin (#1600).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('social_like'), 'social_like already exists.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE social_like (id INT AUTO_INCREMENT NOT NULL, remote_id VARCHAR(512) NOT NULL,'
                . ' liked_at DATETIME NOT NULL, user_id INT NOT NULL, entry_id INT NOT NULL,'
                . ' social_account_id INT NOT NULL,'
                . ' INDEX idx_social_like_user (user_id), INDEX idx_social_like_entry (entry_id),'
                . ' INDEX idx_social_like_account (social_account_id),'
                . ' UNIQUE INDEX uniq_social_like_user_entry (user_id, entry_id), PRIMARY KEY (id))'
                . ' DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE social_like ADD CONSTRAINT fk_social_like_user'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE social_like ADD CONSTRAINT fk_social_like_entry'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE social_like ADD CONSTRAINT fk_social_like_account'
                . ' FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE');

            return;
        }

        $this->addSql('CREATE TABLE social_like (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' remote_id VARCHAR(512) NOT NULL, liked_at DATETIME NOT NULL, user_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_social_like_user FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_like_entry FOREIGN KEY (entry_id) REFERENCES entry (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_like_account FOREIGN KEY (social_account_id) REFERENCES social_account (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_social_like_user ON social_like (user_id)');
        $this->addSql('CREATE INDEX idx_social_like_entry ON social_like (entry_id)');
        $this->addSql('CREATE INDEX idx_social_like_account ON social_like (social_account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_like_user_entry ON social_like (user_id, entry_id)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('social_like'), 'social_like does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE social_like');
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
