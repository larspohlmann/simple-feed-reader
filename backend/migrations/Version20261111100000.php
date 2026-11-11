<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

require_once __DIR__ . '/SplitsUserPreferences.php';

final class Version20261111100000 extends AbstractMigration
{
    use SplitsUserPreferences;

    private const string TABLE = 'user_reading_settings';

    public function getDescription(): string
    {
        return 'Reading settings move from user_preferences to user_reading_settings (#2177).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('CREATE TABLE user_reading_settings (scrape_fallback_enabled TINYINT DEFAULT 0 NOT NULL,'
                . ' magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL, trending_enabled TINYINT DEFAULT 1 NOT NULL,'
                . ' user_id INT NOT NULL, PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE user_reading_settings ADD CONSTRAINT FK_CEA7BFA8A76ED395'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        } else {
            // INT, not INTEGER: a lone INTEGER primary key is a rowid alias, which Doctrine reads back as auto-increment.
            $this->addSql('CREATE TABLE user_reading_settings (scrape_fallback_enabled BOOLEAN DEFAULT 0 NOT NULL,'
                . ' magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL, trending_enabled BOOLEAN DEFAULT 1 NOT NULL,'
                . ' user_id INT NOT NULL, PRIMARY KEY (user_id),'
                . ' CONSTRAINT FK_CEA7BFA8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
                . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        }

        $this->addSql('INSERT INTO user_reading_settings (user_id, scrape_fallback_enabled, magazine_style, trending_enabled)'
            . ' SELECT user_id, scrape_fallback_enabled, magazine_style, trending_enabled FROM user_preferences');
        $this->addSql('INSERT INTO user_reading_settings (user_id)'
            . ' SELECT id FROM app_user WHERE id NOT IN (SELECT user_id FROM user_reading_settings)');
        $this->dropPreferenceColumns('scrape_fallback_enabled', 'magazine_style', 'trending_enabled');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('ALTER TABLE user_preferences ADD scrape_fallback_enabled TINYINT(1) DEFAULT 0 NOT NULL,'
                . ' ADD magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL, ADD trending_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        } else {
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN scrape_fallback_enabled BOOLEAN DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN trending_enabled BOOLEAN DEFAULT 1 NOT NULL');
        }

        $this->restoreColumn('scrape_fallback_enabled', 'scrape_fallback_enabled', '0');
        $this->restoreColumn('magazine_style', 'magazine_style', '\'boxed\'');
        $this->restoreColumn('trending_enabled', 'trending_enabled', '1');
        $this->addSql('DROP TABLE user_reading_settings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
