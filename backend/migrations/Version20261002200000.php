<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Each jev-* row inherits its account's flagged row (the newest of two), as findProfileSourceFor() read it. */
final class Version20261002200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace user_ai_settings.profile_source with a per-connection profile_connection_id (#1349).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_ai_settings')->hasColumn('profile_connection_id'),
            'user_ai_settings.profile_connection_id already exists.',
        );

        if ($this->mysql()) {
            $this->addSql('ALTER TABLE user_ai_settings ADD profile_connection_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE user_ai_settings ADD CONSTRAINT FK_53B8EF3023D39AC1 FOREIGN KEY (profile_connection_id) REFERENCES user_ai_settings (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings (profile_connection_id)');
            $this->addSql(<<<'SQL'
                UPDATE user_ai_settings borrower
                INNER JOIN (
                    SELECT user_id, MAX(id) AS id FROM user_ai_settings WHERE profile_source = 1 GROUP BY user_id
                ) chosen ON chosen.user_id = borrower.user_id
                SET borrower.profile_connection_id = chosen.id
                WHERE CAST(LEFT(borrower.model, 4) AS BINARY) = 'jev-'
                SQL);
            $this->addSql('ALTER TABLE user_ai_settings DROP profile_source');

            return;
        }

        $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN profile_connection_id INTEGER DEFAULT NULL REFERENCES user_ai_settings (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings (profile_connection_id)');
        $this->addSql(<<<'SQL'
            UPDATE user_ai_settings
            SET profile_connection_id = (
                SELECT MAX(chosen.id) FROM user_ai_settings chosen
                WHERE chosen.user_id = user_ai_settings.user_id AND chosen.profile_source = 1
            )
            WHERE substr(model, 1, 4) = 'jev-'
            SQL);
        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN profile_source');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('profile_connection_id'),
            'user_ai_settings.profile_connection_id does not exist.',
        );

        if ($this->mysql()) {
            $this->addSql('ALTER TABLE user_ai_settings ADD profile_source TINYINT(1) DEFAULT 0 NOT NULL');
            $this->addSql(<<<'SQL'
                UPDATE user_ai_settings chosen
                INNER JOIN (
                    SELECT MAX(profile_connection_id) AS id FROM user_ai_settings
                    WHERE profile_connection_id IS NOT NULL GROUP BY user_id
                ) newest ON newest.id = chosen.id
                SET chosen.profile_source = 1
                SQL);
            $this->addSql('ALTER TABLE user_ai_settings DROP FOREIGN KEY FK_53B8EF3023D39AC1');
            $this->addSql('DROP INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings');
            $this->addSql('ALTER TABLE user_ai_settings DROP profile_connection_id');

            return;
        }

        $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN profile_source BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE user_ai_settings SET profile_source = 1
            WHERE id IN (
                SELECT MAX(profile_connection_id) FROM user_ai_settings
                WHERE profile_connection_id IS NOT NULL GROUP BY user_id
            )
            SQL);
        $this->addSql('DROP INDEX IDX_53B8EF3023D39AC1');
        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN profile_connection_id');
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
