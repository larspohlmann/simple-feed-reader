<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118140000 extends AbstractMigration
{
    private const string TABLE = 'user_story_settings';

    public function getDescription(): string
    {
        return 'Add the story settings: the story writing connection and the story-titles switch (#2329).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'The story settings already exist.');

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE user_story_settings (titles_enabled TINYINT DEFAULT 1 NOT NULL,'
                . ' writing_connection_id INT DEFAULT NULL, user_id INT NOT NULL,'
                . ' INDEX IDX_8D256100D3B9B4E3 (writing_connection_id), PRIMARY KEY (user_id))'
                . ' DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE user_story_settings ADD CONSTRAINT FK_8D256100D3B9B4E3'
                . ' FOREIGN KEY (writing_connection_id) REFERENCES user_ai_settings (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE user_story_settings ADD CONSTRAINT FK_8D256100A76ED395'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');

            return;
        }
        $this->requireSqlite($platform);
        // INT, not INTEGER: a lone INTEGER primary key is a rowid alias, which Doctrine reads back as auto-increment.
        $this->addSql('CREATE TABLE user_story_settings (titles_enabled BOOLEAN DEFAULT 1 NOT NULL,'
            . ' writing_connection_id INT DEFAULT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id),'
            . ' CONSTRAINT FK_8D256100D3B9B4E3 FOREIGN KEY (writing_connection_id)'
            . ' REFERENCES user_ai_settings (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_8D256100A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_8D256100D3B9B4E3 ON user_story_settings (writing_connection_id)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'The story settings do not exist.');

        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('DROP INDEX IDX_8D256100D3B9B4E3');
        }
        $this->addSql('DROP TABLE user_story_settings');
    }

    /** MySQL commits each DDL statement, so a transaction would protect nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function requireSqlite(object $platform): void
    {
        $this->abortIf(
            !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
