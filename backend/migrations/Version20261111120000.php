<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

require_once __DIR__ . '/SplitsUserPreferences.php';

final class Version20261111120000 extends AbstractMigration
{
    use SplitsUserPreferences;

    private const string TABLE = 'user_digest_settings';

    public function getDescription(): string
    {
        return 'Digest settings move from user_preferences to user_digest_settings (#2177).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('CREATE TABLE user_digest_settings (enabled TINYINT DEFAULT 0 NOT NULL,'
                . ' cadence VARCHAR(10) DEFAULT \'daily\' NOT NULL,'
                . ' format VARCHAR(10) DEFAULT \'html\' NOT NULL, send_hour SMALLINT DEFAULT 8 NOT NULL,'
                . ' weekday SMALLINT DEFAULT 1 NOT NULL, last_sent_at DATETIME DEFAULT NULL, user_id INT NOT NULL,'
                . ' PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE user_digest_settings ADD CONSTRAINT FK_59591884A76ED395'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        } else {
            // INT, not INTEGER: a lone INTEGER primary key is a rowid alias, which Doctrine reads back as auto-increment.
            $this->addSql('CREATE TABLE user_digest_settings (enabled BOOLEAN DEFAULT 0 NOT NULL,'
                . ' cadence VARCHAR(10) DEFAULT \'daily\' NOT NULL,'
                . ' format VARCHAR(10) DEFAULT \'html\' NOT NULL, send_hour SMALLINT DEFAULT 8 NOT NULL,'
                . ' weekday SMALLINT DEFAULT 1 NOT NULL, last_sent_at DATETIME DEFAULT NULL, user_id INT NOT NULL,'
                . ' PRIMARY KEY (user_id),'
                . ' CONSTRAINT FK_59591884A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
                . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        }

        $this->addSql('INSERT INTO user_digest_settings (user_id, enabled, cadence, format, send_hour, weekday, last_sent_at)'
            . ' SELECT user_id, digest_enabled, digest_cadence, digest_format, digest_send_hour, digest_weekday,'
            . ' digest_last_sent_at FROM user_preferences');
        $this->addSql('INSERT INTO user_digest_settings (user_id)'
            . ' SELECT id FROM app_user WHERE id NOT IN (SELECT user_id FROM user_digest_settings)');
        $this->dropPreferenceColumns(
            'digest_enabled',
            'digest_cadence',
            'digest_format',
            'digest_send_hour',
            'digest_weekday',
            'digest_last_sent_at',
        );
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('ALTER TABLE user_preferences ADD digest_enabled TINYINT(1) DEFAULT 0 NOT NULL,'
                . ' ADD digest_cadence VARCHAR(10) DEFAULT \'daily\' NOT NULL,'
                . ' ADD digest_send_hour SMALLINT DEFAULT 8 NOT NULL, ADD digest_weekday SMALLINT DEFAULT 1 NOT NULL,'
                . ' ADD digest_last_sent_at DATETIME DEFAULT NULL,'
                . ' ADD digest_format VARCHAR(10) DEFAULT \'html\' NOT NULL');
        } else {
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_enabled BOOLEAN DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_cadence VARCHAR(10) DEFAULT \'daily\' NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_send_hour SMALLINT DEFAULT 8 NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_weekday SMALLINT DEFAULT 1 NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_last_sent_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_format VARCHAR(10) DEFAULT \'html\' NOT NULL');
        }

        $this->restoreColumn('digest_enabled', 'enabled', '0');
        $this->restoreColumn('digest_cadence', 'cadence', '\'daily\'');
        $this->restoreColumn('digest_send_hour', 'send_hour', '8');
        $this->restoreColumn('digest_weekday', 'weekday', '1');
        $this->restoreColumn('digest_last_sent_at', 'last_sent_at', null);
        $this->restoreColumn('digest_format', 'format', '\'html\'');
        $this->addSql('DROP TABLE user_digest_settings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
