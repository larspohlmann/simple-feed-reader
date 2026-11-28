<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** No account has the stream yet (#2601), so the old switch and precision are dropped, not carried over. */
final class Version20261128150000 extends AbstractMigration
{
    private const string MYSQL_TABLE_OPTIONS = ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'
        . ' ENGINE = InnoDB';
    private const string SQLITE_FK_TAIL = ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE';
    private const string RECOMMENDATION_SETTINGS = 'user_recommendation_settings';
    private const array OLD_COLUMNS = ['for_you_stream_enabled', 'for_you_stream_precision'];

    public function getDescription(): string
    {
        return 'Your stream settings move to their own row; the AI term storage is dropped (#2601).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('your_stream_settings'), 'Already applied.');

        if ($this->mysql()) {
            $this->upMySql();

            return;
        }

        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('your_stream_settings'), 'Not applied.');

        if ($this->mysql()) {
            $this->downMySql();

            return;
        }

        $this->downSqlite();
    }

    public function isTransactional(): bool
    {
        return !$this->mysql();
    }

    private function upMySql(): void
    {
        $this->addSql('CREATE TABLE your_stream_settings (enabled TINYINT(1) DEFAULT 1 NOT NULL,'
            . " match_precision VARCHAR(10) DEFAULT 'wide' NOT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id))"
            . self::MYSQL_TABLE_OPTIONS);
        $this->addSql('ALTER TABLE your_stream_settings ADD CONSTRAINT FK_DD654C99A76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql(\sprintf(
            'ALTER TABLE %s DROP COLUMN %s, DROP COLUMN %s',
            self::RECOMMENDATION_SETTINGS,
            ...self::OLD_COLUMNS,
        ));
        $this->addSql('ALTER TABLE interest_term DROP FOREIGN KEY FK_B216701A76ED395');
        $this->addSql('ALTER TABLE interest_term_set DROP FOREIGN KEY FK_45164A8DA76ED395');
        $this->dropTermTables();
    }

    private function upSqlite(): void
    {
        // INT, not INTEGER: a lone INTEGER primary key is a rowid alias, which Doctrine reads back as auto-increment.
        $this->addSql('CREATE TABLE your_stream_settings (enabled BOOLEAN DEFAULT 1 NOT NULL,'
            . " match_precision VARCHAR(10) DEFAULT 'wide' NOT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id),"
            . ' CONSTRAINT FK_DD654C99A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . self::SQLITE_FK_TAIL . ')');
        foreach (self::OLD_COLUMNS as $column) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', self::RECOMMENDATION_SETTINGS, $column));
        }
        $this->dropTermTables();
    }

    private function downMySql(): void
    {
        $this->addSql(\sprintf(
            "ALTER TABLE %s ADD %s TINYINT(1) DEFAULT 1 NOT NULL, ADD %s VARCHAR(10) DEFAULT 'wide' NOT NULL",
            self::RECOMMENDATION_SETTINGS,
            ...self::OLD_COLUMNS,
        ));
        $this->addSql('CREATE TABLE interest_term (id INT AUTO_INCREMENT NOT NULL, stem VARCHAR(64) NOT NULL,'
            . ' language VARCHAR(2) DEFAULT NULL, weight DOUBLE PRECISION NOT NULL,'
            . ' rarity DOUBLE PRECISION DEFAULT 1 NOT NULL, user_id INT NOT NULL,'
            . ' INDEX idx_interest_term_user (user_id), PRIMARY KEY (id))' . self::MYSQL_TABLE_OPTIONS);
        $this->addSql('CREATE TABLE interest_term_set (profile_generated_at DATETIME NOT NULL,'
            . ' stored_at DATETIME NOT NULL, rarities_refreshed_at DATETIME DEFAULT NULL,'
            . ' matched_up_to_entry_id INT DEFAULT 0 NOT NULL, user_id INT NOT NULL, phrases JSON DEFAULT NULL,'
            . ' PRIMARY KEY (user_id))' . self::MYSQL_TABLE_OPTIONS);
        $this->addSql('ALTER TABLE interest_term ADD CONSTRAINT FK_B216701A76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interest_term_set ADD CONSTRAINT FK_45164A8DA76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE your_stream_settings DROP FOREIGN KEY FK_DD654C99A76ED395');
        $this->addSql('DROP TABLE your_stream_settings');
    }

    private function downSqlite(): void
    {
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD COLUMN %s BOOLEAN DEFAULT 1 NOT NULL',
            self::RECOMMENDATION_SETTINGS,
            self::OLD_COLUMNS[0],
        ));
        $this->addSql(\sprintf(
            "ALTER TABLE %s ADD COLUMN %s VARCHAR(10) DEFAULT 'wide' NOT NULL",
            self::RECOMMENDATION_SETTINGS,
            self::OLD_COLUMNS[1],
        ));
        $this->addSql('CREATE TABLE interest_term (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' stem VARCHAR(64) NOT NULL, language VARCHAR(2) DEFAULT NULL, weight DOUBLE PRECISION NOT NULL,'
            . ' rarity DOUBLE PRECISION DEFAULT 1 NOT NULL, user_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_B216701A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . self::SQLITE_FK_TAIL . ')');
        $this->addSql('CREATE INDEX idx_interest_term_user ON interest_term (user_id)');
        $this->addSql('CREATE TABLE interest_term_set (profile_generated_at DATETIME NOT NULL,'
            . ' stored_at DATETIME NOT NULL, rarities_refreshed_at DATETIME DEFAULT NULL,'
            . ' matched_up_to_entry_id INTEGER DEFAULT 0 NOT NULL, user_id INT NOT NULL, phrases CLOB DEFAULT NULL,'
            . ' PRIMARY KEY (user_id),'
            . ' CONSTRAINT FK_45164A8DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . self::SQLITE_FK_TAIL . ')');
        $this->addSql('DROP TABLE your_stream_settings');
    }

    private function dropTermTables(): void
    {
        $this->addSql('DROP TABLE interest_term');
        $this->addSql('DROP TABLE interest_term_set');
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
