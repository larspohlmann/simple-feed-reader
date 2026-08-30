<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates `user_passkey`: one row per registered WebAuthn credential (#624,
 * spec §4.1).
 *
 * PLATFORM-AWARE DDL, hand-written for the same reason Version20260721181500
 * is: `doctrine:migrations:diff` emits DDL for whichever platform it ran
 * against, and `credential_id`/`user_handle` need `utf8mb4_bin` pinned
 * explicitly on MySQL, the way `user_identity.provider_user_id` does — a
 * credential id and a user handle are opaque tokens where `a` and `A` are
 * different values, and MySQL would otherwise compare them case-insensitively
 * under the table's default collation. SQLite has no `utf8mb4_bin` collation
 * registered, so naming it there fails the CREATE TABLE outright rather than
 * silently doing nothing; SQLite's own default column collation (BINARY) is
 * already case-sensitive, which is the same convergence
 * Version20260721181500 records for provider_user_id.
 */
final class Version20260829155356 extends AbstractMigration
{
    private const TABLE = 'user_passkey';

    public function getDescription(): string
    {
        return 'Create user_passkey: WebAuthn credentials, one row per registered authenticator (#624).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'user_passkey already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(
                'CREATE TABLE user_passkey (id INT AUTO_INCREMENT NOT NULL, '
                . 'credential_id VARCHAR(255) NOT NULL COLLATE `utf8mb4_bin`, '
                . 'user_handle VARCHAR(64) NOT NULL COLLATE `utf8mb4_bin`, '
                . 'public_key LONGTEXT NOT NULL, '
                . 'signature_counter INT NOT NULL, '
                . 'aaguid VARCHAR(36) DEFAULT NULL, '
                . 'transports JSON NOT NULL, '
                . 'label VARCHAR(100) NOT NULL, '
                . 'created_at DATETIME NOT NULL, '
                . 'last_used_at DATETIME DEFAULT NULL, '
                . 'user_id INT NOT NULL, '
                . 'INDEX IDX_58882A76A76ED395 (user_id), '
                . 'UNIQUE INDEX uniq_passkey_credential_id (credential_id), '
                . 'PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB',
            );
            $this->addSql(
                'ALTER TABLE user_passkey ADD CONSTRAINT FK_58882A76A76ED395 '
                . 'FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
            );

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(
                'CREATE TABLE user_passkey (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
                . 'credential_id VARCHAR(255) NOT NULL, '
                . 'user_handle VARCHAR(64) NOT NULL, '
                . 'public_key CLOB NOT NULL, '
                . 'signature_counter INTEGER NOT NULL, '
                . 'aaguid VARCHAR(36) DEFAULT NULL, '
                . 'transports CLOB NOT NULL, '
                . 'label VARCHAR(100) NOT NULL, '
                . 'created_at DATETIME NOT NULL, '
                . 'last_used_at DATETIME DEFAULT NULL, '
                . 'user_id INTEGER NOT NULL, '
                . 'CONSTRAINT FK_58882A76A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) '
                . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            );
            $this->addSql('CREATE INDEX IDX_58882A76A76ED395 ON user_passkey (user_id)');
            $this->addSql('CREATE UNIQUE INDEX uniq_passkey_credential_id ON user_passkey (credential_id)');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for user_passkey migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'user_passkey does not exist.');

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_passkey DROP FOREIGN KEY FK_58882A76A76ED395');
        }

        $this->addSql('DROP TABLE user_passkey');
    }
}
