<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127125000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'interest_term_set and interest_term hold the for you stream\'s terms and sweep mark (#2582)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('interest_term'), 'interest_term already exists.');

        if ($this->mysql()) {
            $this->upMySql();

            return;
        }

        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('interest_term'), 'interest_term does not exist.');

        if ($this->mysql()) {
            $this->addSql('ALTER TABLE interest_term DROP FOREIGN KEY FK_B216701A76ED395');
            $this->addSql('ALTER TABLE interest_term_set DROP FOREIGN KEY FK_45164A8DA76ED395');
        }
        $this->addSql('DROP TABLE interest_term');
        $this->addSql('DROP TABLE interest_term_set');
    }

    /** MySQL commits each DDL statement, so a wrapping transaction protects nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql('CREATE TABLE interest_term (id INT AUTO_INCREMENT NOT NULL, stem VARCHAR(64) NOT NULL,'
            . ' language VARCHAR(2) DEFAULT NULL, weight DOUBLE PRECISION NOT NULL,'
            . ' rarity DOUBLE PRECISION DEFAULT 1 NOT NULL, user_id INT NOT NULL,'
            . ' INDEX idx_interest_term_user (user_id), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE interest_term_set (profile_generated_at DATETIME NOT NULL,'
            . ' stored_at DATETIME NOT NULL, rarities_refreshed_at DATETIME DEFAULT NULL,'
            . ' matched_up_to_entry_id INT DEFAULT 0 NOT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id))'
            . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE interest_term ADD CONSTRAINT FK_B216701A76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interest_term_set ADD CONSTRAINT FK_45164A8DA76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE');
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE interest_term (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' stem VARCHAR(64) NOT NULL, language VARCHAR(2) DEFAULT NULL, weight DOUBLE PRECISION NOT NULL,'
            . ' rarity DOUBLE PRECISION DEFAULT 1 NOT NULL, user_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_B216701A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_interest_term_user ON interest_term (user_id)');
        // INT, not INTEGER: a lone INTEGER primary key is a rowid alias, which Doctrine reads back as auto-increment.
        $this->addSql('CREATE TABLE interest_term_set (profile_generated_at DATETIME NOT NULL,'
            . ' stored_at DATETIME NOT NULL, rarities_refreshed_at DATETIME DEFAULT NULL,'
            . ' matched_up_to_entry_id INTEGER DEFAULT 0 NOT NULL, user_id INT NOT NULL,'
            . ' PRIMARY KEY (user_id),'
            . ' CONSTRAINT FK_45164A8DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
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
