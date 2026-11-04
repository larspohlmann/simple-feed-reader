<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105090000 extends AbstractMigration
{
    private const string SNAPSHOT_TABLE = 'trending_snapshot';

    public function getDescription(): string
    {
        return 'Trending snapshots: trending_snapshot, trending_story, trending_story_entry (#2017).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::SNAPSHOT_TABLE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->upOnMySql();

            return;
        }

        $this->upOnSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::SNAPSHOT_TABLE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE trending_story_entry');
        $this->addSql('DROP TABLE trending_story');
        $this->addSql('DROP TABLE ' . self::SNAPSHOT_TABLE);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upOnMySql(): void
    {
        $this->addSql('CREATE TABLE trending_snapshot (time_window VARCHAR(8) NOT NULL, built_at DATETIME NOT NULL,'
            . ' entry_count INT NOT NULL, story_count INT NOT NULL, duration_ms INT NOT NULL,'
            . ' peak_memory_bytes INT NOT NULL, PRIMARY KEY (time_window)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trending_story (id INT AUTO_INCREMENT NOT NULL, time_window VARCHAR(8) NOT NULL,'
            . ' first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL, outlet_count INT NOT NULL,'
            . ' INDEX idx_trending_story_window_outlets (time_window, outlet_count), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trending_story_entry (feed_id INT NOT NULL, story_id INT NOT NULL,'
            . ' entry_id INT NOT NULL, INDEX IDX_8C835665AA5D4036 (story_id), INDEX IDX_8C835665BA364942 (entry_id),'
            . ' INDEX idx_trending_story_entry_feed (feed_id), PRIMARY KEY (story_id, entry_id))'
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE trending_story_entry ADD CONSTRAINT FK_8C835665AA5D4036'
            . ' FOREIGN KEY (story_id) REFERENCES trending_story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trending_story_entry ADD CONSTRAINT FK_8C835665BA364942'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
    }

    private function upOnSqlite(): void
    {
        $this->addSql('CREATE TABLE trending_snapshot (time_window VARCHAR(8) NOT NULL, built_at DATETIME NOT NULL,'
            . ' entry_count INTEGER NOT NULL, story_count INTEGER NOT NULL, duration_ms INTEGER NOT NULL,'
            . ' peak_memory_bytes INTEGER NOT NULL, PRIMARY KEY (time_window))');
        $this->addSql('CREATE TABLE trending_story (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' time_window VARCHAR(8) NOT NULL, first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL,'
            . ' outlet_count INTEGER NOT NULL)');
        $this->addSql('CREATE INDEX idx_trending_story_window_outlets ON trending_story (time_window, outlet_count)');
        $this->addSql('CREATE TABLE trending_story_entry (feed_id INTEGER NOT NULL, story_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, PRIMARY KEY (story_id, entry_id),'
            . ' CONSTRAINT FK_8C835665AA5D4036 FOREIGN KEY (story_id)'
            . ' REFERENCES trending_story (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_8C835665BA364942 FOREIGN KEY (entry_id)'
            . ' REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_8C835665AA5D4036 ON trending_story_entry (story_id)');
        $this->addSql('CREATE INDEX IDX_8C835665BA364942 ON trending_story_entry (entry_id)');
        $this->addSql('CREATE INDEX idx_trending_story_entry_feed ON trending_story_entry (feed_id)');
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
