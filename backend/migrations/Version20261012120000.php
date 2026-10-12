<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create social_account: connected Bluesky and Mastodon accounts (#1597).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('social_account'), 'social_account already exists.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE social_account (id INT AUTO_INCREMENT NOT NULL, network VARCHAR(16) NOT NULL,'
                . ' identity VARCHAR(255) NOT NULL, handle VARCHAR(255) NOT NULL, service_url VARCHAR(512) NOT NULL,'
                . ' credential_ciphertext VARCHAR(1024) NOT NULL, credential_nonce VARCHAR(64) NOT NULL,'
                . ' credential_salt VARCHAR(64) NOT NULL, credential_hint VARCHAR(8) NOT NULL,'
                . ' credential_version INT DEFAULT 1 NOT NULL, status VARCHAR(24) NOT NULL,'
                . ' is_default TINYINT DEFAULT 0 NOT NULL, connected_at DATETIME NOT NULL, user_id INT NOT NULL,'
                . ' INDEX IDX_F24D8339A76ED395 (user_id),'
                . ' UNIQUE INDEX uniq_social_account_identity (user_id, network, identity), PRIMARY KEY (id))'
                . ' DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE social_account ADD CONSTRAINT FK_F24D8339A76ED395'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');

            return;
        }

        $this->addSql('CREATE TABLE social_account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' network VARCHAR(16) NOT NULL, identity VARCHAR(255) NOT NULL, handle VARCHAR(255) NOT NULL,'
            . ' service_url VARCHAR(512) NOT NULL, credential_ciphertext VARCHAR(1024) NOT NULL,'
            . ' credential_nonce VARCHAR(64) NOT NULL, credential_salt VARCHAR(64) NOT NULL,'
            . ' credential_hint VARCHAR(8) NOT NULL, credential_version INTEGER DEFAULT 1 NOT NULL,'
            . ' status VARCHAR(24) NOT NULL, is_default BOOLEAN DEFAULT 0 NOT NULL, connected_at DATETIME NOT NULL,'
            . ' user_id INTEGER NOT NULL, CONSTRAINT FK_F24D8339A76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F24D8339A76ED395 ON social_account (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_account_identity ON social_account (user_id, network, identity)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('social_account'), 'social_account does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE social_account');
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
