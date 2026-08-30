<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260830090229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_preferences ADD COLUMN magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__user_preferences AS SELECT id, scrape_fallback_enabled, digest_enabled, digest_cadence, digest_send_hour, digest_weekday, digest_last_sent_at, passkey_offer_answered_at, user_id FROM user_preferences');
        $this->addSql('DROP TABLE user_preferences');
        $this->addSql('CREATE TABLE user_preferences (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, scrape_fallback_enabled BOOLEAN DEFAULT 0 NOT NULL, digest_enabled BOOLEAN DEFAULT 0 NOT NULL, digest_cadence VARCHAR(10) DEFAULT \'daily\' NOT NULL, digest_send_hour SMALLINT DEFAULT 8 NOT NULL, digest_weekday SMALLINT DEFAULT 1 NOT NULL, digest_last_sent_at DATETIME DEFAULT NULL, passkey_offer_answered_at DATETIME DEFAULT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_402A6F60A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_preferences (id, scrape_fallback_enabled, digest_enabled, digest_cadence, digest_send_hour, digest_weekday, digest_last_sent_at, passkey_offer_answered_at, user_id) SELECT id, scrape_fallback_enabled, digest_enabled, digest_cadence, digest_send_hour, digest_weekday, digest_last_sent_at, passkey_offer_answered_at, user_id FROM __temp__user_preferences');
        $this->addSql('DROP TABLE __temp__user_preferences');
        $this->addSql('CREATE UNIQUE INDEX uniq_preferences_user ON user_preferences (user_id)');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
