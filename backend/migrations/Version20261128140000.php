<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** No account has stream data yet (#2601), so the old memberships are dropped, not copied. */
final class Version20261128140000 extends AbstractMigration
{
    private const array NEW_TABLES = ['your_stream_word', 'your_stream_state', 'your_stream_entry'];
    private const string MYSQL_TABLE_OPTIONS = ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'
        . ' ENGINE = InnoDB';
    private const string SQLITE_FK_TAIL = ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE';

    public function getDescription(): string
    {
        return 'Your stream tables: local word list, state with build marks, renamed memberships (#2601).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('your_stream_state'), 'Already applied.');

        if ($this->mysql()) {
            $this->upMySql();

            return;
        }

        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('your_stream_state'), 'Not applied.');

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
        $this->addSql('CREATE TABLE your_stream_word (id INT AUTO_INCREMENT NOT NULL, language VARCHAR(2) DEFAULT NULL,'
            . ' stem VARCHAR(64) NOT NULL, word VARCHAR(100) NOT NULL, weight DOUBLE PRECISION NOT NULL,'
            . ' rarity DOUBLE PRECISION NOT NULL, user_id INT NOT NULL,'
            . ' INDEX idx_your_stream_word_user (user_id), PRIMARY KEY (id))' . self::MYSQL_TABLE_OPTIONS);
        $this->addSql('CREATE TABLE your_stream_state (matched_up_to_entry_id INT DEFAULT 0 NOT NULL,'
            . ' interest_fingerprint VARCHAR(40) DEFAULT NULL, words_checked_at DATETIME DEFAULT NULL,'
            . ' words_built_at DATETIME DEFAULT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id))'
            . self::MYSQL_TABLE_OPTIONS);
        $this->addSql('CREATE TABLE your_stream_entry (matched_at DATETIME NOT NULL,'
            . ' score DOUBLE PRECISION NOT NULL, matched_stems VARCHAR(255) NOT NULL,'
            . ' user_id INT NOT NULL, entry_id INT NOT NULL, INDEX IDX_74FA1E51A76ED395 (user_id),'
            . ' INDEX idx_your_stream_entry_entry (entry_id), PRIMARY KEY (user_id, entry_id))'
            . self::MYSQL_TABLE_OPTIONS);
        $this->addMySqlForeignKey('your_stream_word', 'FK_1DFA5C28A76ED395', 'user_id', 'app_user');
        $this->addMySqlForeignKey('your_stream_state', 'FK_FC4851DAA76ED395', 'user_id', 'app_user');
        $this->addMySqlForeignKey('your_stream_entry', 'FK_74FA1E51A76ED395', 'user_id', 'app_user');
        $this->addMySqlForeignKey('your_stream_entry', 'FK_74FA1E51BA364942', 'entry_id', 'entry');
        $this->addSql('ALTER TABLE for_you_stream_entry DROP FOREIGN KEY FK_87C97081A76ED395');
        $this->addSql('ALTER TABLE for_you_stream_entry DROP FOREIGN KEY FK_87C97081BA364942');
        $this->addSql('DROP TABLE for_you_stream_entry');
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE your_stream_word (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' language VARCHAR(2) DEFAULT NULL, stem VARCHAR(64) NOT NULL, word VARCHAR(100) NOT NULL,'
            . ' weight DOUBLE PRECISION NOT NULL, rarity DOUBLE PRECISION NOT NULL, user_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_1DFA5C28A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . self::SQLITE_FK_TAIL . ')');
        $this->addSql('CREATE INDEX idx_your_stream_word_user ON your_stream_word (user_id)');
        // INT, not INTEGER: a lone INTEGER primary key is a rowid alias, which Doctrine reads back as auto-increment.
        $this->addSql('CREATE TABLE your_stream_state (matched_up_to_entry_id INTEGER DEFAULT 0 NOT NULL,'
            . ' interest_fingerprint VARCHAR(40) DEFAULT NULL, words_checked_at DATETIME DEFAULT NULL,'
            . ' words_built_at DATETIME DEFAULT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id),'
            . ' CONSTRAINT FK_FC4851DAA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . self::SQLITE_FK_TAIL . ')');
        $this->addSql('CREATE TABLE your_stream_entry (matched_at DATETIME NOT NULL,'
            . ' score DOUBLE PRECISION NOT NULL, matched_stems VARCHAR(255) NOT NULL,'
            . ' user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, PRIMARY KEY (user_id, entry_id),'
            . ' CONSTRAINT FK_74FA1E51A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . self::SQLITE_FK_TAIL . ','
            . ' CONSTRAINT FK_74FA1E51BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id)'
            . self::SQLITE_FK_TAIL . ')');
        $this->addSql('CREATE INDEX IDX_74FA1E51A76ED395 ON your_stream_entry (user_id)');
        $this->addSql('CREATE INDEX idx_your_stream_entry_entry ON your_stream_entry (entry_id)');
        $this->addSql('DROP TABLE for_you_stream_entry');
    }

    private function downMySql(): void
    {
        $this->addSql('CREATE TABLE for_you_stream_entry (matched_at DATETIME NOT NULL,'
            . ' score DOUBLE PRECISION NOT NULL, matched_stems VARCHAR(255) NOT NULL,'
            . ' user_id INT NOT NULL, entry_id INT NOT NULL, INDEX IDX_87C97081A76ED395 (user_id),'
            . ' INDEX idx_for_you_stream_entry_entry (entry_id), PRIMARY KEY (user_id, entry_id))'
            . self::MYSQL_TABLE_OPTIONS);
        $this->addMySqlForeignKey('for_you_stream_entry', 'FK_87C97081A76ED395', 'user_id', 'app_user');
        $this->addMySqlForeignKey('for_you_stream_entry', 'FK_87C97081BA364942', 'entry_id', 'entry');
        $this->addSql('ALTER TABLE your_stream_word DROP FOREIGN KEY FK_1DFA5C28A76ED395');
        $this->addSql('ALTER TABLE your_stream_state DROP FOREIGN KEY FK_FC4851DAA76ED395');
        $this->addSql('ALTER TABLE your_stream_entry DROP FOREIGN KEY FK_74FA1E51A76ED395');
        $this->addSql('ALTER TABLE your_stream_entry DROP FOREIGN KEY FK_74FA1E51BA364942');
        $this->dropNewTables();
    }

    private function downSqlite(): void
    {
        $this->addSql('CREATE TABLE for_you_stream_entry (matched_at DATETIME NOT NULL,'
            . ' score DOUBLE PRECISION NOT NULL, matched_stems VARCHAR(255) NOT NULL,'
            . ' user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, PRIMARY KEY (user_id, entry_id),'
            . ' CONSTRAINT FK_87C97081A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . self::SQLITE_FK_TAIL . ','
            . ' CONSTRAINT FK_87C97081BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id)'
            . self::SQLITE_FK_TAIL . ')');
        $this->addSql('CREATE INDEX IDX_87C97081A76ED395 ON for_you_stream_entry (user_id)');
        $this->addSql('CREATE INDEX idx_for_you_stream_entry_entry ON for_you_stream_entry (entry_id)');
        $this->dropNewTables();
    }

    private function dropNewTables(): void
    {
        foreach (self::NEW_TABLES as $table) {
            $this->addSql(\sprintf('DROP TABLE %s', $table));
        }
    }

    private function addMySqlForeignKey(string $table, string $name, string $column, string $target): void
    {
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE CASCADE',
            $table,
            $name,
            $column,
            $target,
        ));
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
