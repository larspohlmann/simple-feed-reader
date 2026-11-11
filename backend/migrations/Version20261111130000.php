<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

require_once __DIR__ . '/SplitsUserPreferences.php';

final class Version20261111130000 extends AbstractMigration
{
    use SplitsUserPreferences;

    public function getDescription(): string
    {
        return 'The passkey offer marker moves onto app_user; user_preferences is dropped (#2177).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfHalfMoved($schema);
        $this->skipIf(!$schema->hasTable('user_preferences'), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('ALTER TABLE app_user ADD passkey_offer_answered_at DATETIME DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE app_user ADD COLUMN passkey_offer_answered_at DATETIME DEFAULT NULL');
        }

        $this->addSql('UPDATE app_user SET passkey_offer_answered_at ='
            . ' (SELECT p.passkey_offer_answered_at FROM user_preferences p WHERE p.user_id = app_user.id)');
        $this->addSql('DROP TABLE user_preferences');
    }

    public function down(Schema $schema): void
    {
        $this->abortIfHalfMoved($schema);
        $this->skipIf($schema->hasTable('user_preferences'), 'Not applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('CREATE TABLE user_preferences (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL,'
                . ' passkey_offer_answered_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_preferences_user (user_id),'
                . ' PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE user_preferences ADD CONSTRAINT fk_preferences_user'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        } else {
            $this->addSql('CREATE TABLE user_preferences (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
                . ' user_id INTEGER NOT NULL, passkey_offer_answered_at DATETIME DEFAULT NULL,'
                . ' CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES app_user (id)'
                . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX uniq_preferences_user ON user_preferences (user_id)');
        }

        $this->addSql('INSERT INTO user_preferences (user_id, passkey_offer_answered_at)'
            . ' SELECT id, passkey_offer_answered_at FROM app_user');
        $this->addSql($this->onMySql()
            ? 'ALTER TABLE app_user DROP passkey_offer_answered_at'
            : 'ALTER TABLE app_user DROP COLUMN passkey_offer_answered_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function abortIfHalfMoved(Schema $schema): void
    {
        $this->abortIf(
            $schema->hasTable('user_preferences') && $schema->getTable('app_user')->hasColumn('passkey_offer_answered_at'),
            'Both user_preferences and app_user.passkey_offer_answered_at exist: an earlier run stopped part-way'
            . ' (DDL does not roll back). Repair by hand: decide which side holds the data, drop the other, then rerun.',
        );
    }
}
