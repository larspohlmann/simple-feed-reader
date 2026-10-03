<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The active Jev connection's profile connection becomes the account's; an inactive Jev row's pick is dropped. */
final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the profile connection from user_ai_settings to user_recommendation_settings (#1351).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('profile_connection_id'),
            'user_ai_settings.profile_connection_id is already gone.',
        );

        if ($this->mysql()) {
            $this->upMySql();

            return;
        }

        $this->upSqlite();
    }

    public function down(Schema $schema): void
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
                UPDATE user_ai_settings active
                INNER JOIN app_user u ON u.active_ai_config_id = active.id
                INNER JOIN user_recommendation_settings s ON s.user_id = u.id
                SET active.profile_connection_id = s.profile_connection_id
                WHERE CAST(LEFT(active.model, 4) AS BINARY) = 'jev-'
                SQL);

            return;
        }

        $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN profile_connection_id INTEGER DEFAULT NULL REFERENCES user_ai_settings (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings (profile_connection_id)');
        $this->addSql(<<<'SQL'
            UPDATE user_ai_settings
            SET profile_connection_id = (
                SELECT s.profile_connection_id FROM user_recommendation_settings s
                INNER JOIN app_user u ON u.id = s.user_id
                WHERE u.active_ai_config_id = user_ai_settings.id
            )
            WHERE substr(model, 1, 4) = 'jev-'
            SQL);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO user_recommendation_settings (user_id)
            SELECT u.id FROM app_user u
            INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
            WHERE CAST(LEFT(active.model, 4) AS BINARY) = 'jev-' AND active.profile_connection_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM user_recommendation_settings s WHERE s.user_id = u.id)
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE user_recommendation_settings s
            INNER JOIN app_user u ON u.id = s.user_id
            INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
            SET s.profile_connection_id = active.profile_connection_id
            WHERE CAST(LEFT(active.model, 4) AS BINARY) = 'jev-' AND active.profile_connection_id IS NOT NULL
            SQL);
        $this->addSql('ALTER TABLE user_ai_settings DROP FOREIGN KEY FK_53B8EF3023D39AC1');
        $this->addSql('DROP INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings');
        $this->addSql('ALTER TABLE user_ai_settings DROP profile_connection_id');
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO user_recommendation_settings (user_id)
            SELECT u.id FROM app_user u
            INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
            WHERE substr(active.model, 1, 4) = 'jev-' AND active.profile_connection_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM user_recommendation_settings s WHERE s.user_id = u.id)
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE user_recommendation_settings
            SET profile_connection_id = (
                SELECT active.profile_connection_id FROM app_user u
                INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
                WHERE u.id = user_recommendation_settings.user_id
                  AND substr(active.model, 1, 4) = 'jev-' AND active.profile_connection_id IS NOT NULL
            )
            WHERE user_id IN (
                SELECT u.id FROM app_user u
                INNER JOIN user_ai_settings active ON active.id = u.active_ai_config_id
                WHERE substr(active.model, 1, 4) = 'jev-' AND active.profile_connection_id IS NOT NULL
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
