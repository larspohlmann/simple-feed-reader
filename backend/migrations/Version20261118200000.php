<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SQLite drops entry.score in place, so the entry_search triggers on entry stay: none of them names that column.
 */
final class Version20261118200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'entry_lemmy_post holds a Lemmy post\'s community and score (#2347)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('entry_lemmy_post'), 'entry_lemmy_post already exists.');

        if ($this->mysql()) {
            $this->upMySql();

            return;
        }

        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('entry_lemmy_post'), 'entry_lemmy_post does not exist.');

        if ($this->mysql()) {
            $this->downMySql();

            return;
        }

        $this->downSqlite();
    }

    /** MySQL commits each DDL statement, so a wrapping transaction protects nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql('CREATE TABLE entry_lemmy_post (id INT AUTO_INCREMENT NOT NULL, score INT DEFAULT NULL,'
            . ' community_identity VARCHAR(255) DEFAULT NULL, community_url VARCHAR(2048) DEFAULT NULL,'
            . ' entry_id INT NOT NULL, UNIQUE INDEX UNIQ_EA202E9CBA364942 (entry_id), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE entry_lemmy_post ADD CONSTRAINT FK_EA202E9CBA364942 FOREIGN KEY (entry_id)'
            . ' REFERENCES entry (id) ON DELETE CASCADE');
        $this->addSql('INSERT INTO entry_lemmy_post (entry_id, score) SELECT id, score FROM entry WHERE score IS NOT NULL');
        $this->addSql('ALTER TABLE entry DROP score');
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE entry_lemmy_post (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' score INTEGER DEFAULT NULL, community_identity VARCHAR(255) DEFAULT NULL,'
            . ' community_url VARCHAR(2048) DEFAULT NULL, entry_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_EA202E9CBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EA202E9CBA364942 ON entry_lemmy_post (entry_id)');
        $this->addSql('INSERT INTO entry_lemmy_post (entry_id, score) SELECT id, score FROM entry WHERE score IS NOT NULL');
        $this->addSql('ALTER TABLE entry DROP COLUMN score');
    }

    private function downMySql(): void
    {
        $this->addSql('ALTER TABLE entry ADD score INT DEFAULT NULL');
        $this->addSql('UPDATE entry INNER JOIN entry_lemmy_post post ON post.entry_id = entry.id'
            . ' SET entry.score = post.score');
        $this->addSql('ALTER TABLE entry_lemmy_post DROP FOREIGN KEY FK_EA202E9CBA364942');
        $this->addSql('DROP TABLE entry_lemmy_post');
    }

    private function downSqlite(): void
    {
        $this->addSql('ALTER TABLE entry ADD COLUMN score INTEGER DEFAULT NULL');
        $this->addSql('UPDATE entry SET score = (SELECT post.score FROM entry_lemmy_post post WHERE post.entry_id = entry.id)');
        $this->addSql('DROP TABLE entry_lemmy_post');
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
