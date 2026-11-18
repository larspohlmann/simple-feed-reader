<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118120000 extends AbstractMigration
{
    private const array TABLES_IN_FOREIGN_KEY_ORDER = [
        'trending_story_follow',
        'trending_story_hide',
        'trending_story_placement',
        'trending_story_entry',
        'trending_snapshot',
        'trending_story',
    ];

    public function getDescription(): string
    {
        return 'Trending stories, placements and snapshots belong to one user (#2328); the unreleased rows are dropped.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('trending_story')->hasColumn('user_id'),
            'Trending stories already belong to a user.',
        );

        $this->deleteTrendingRows();
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMysql();

            return;
        }
        $this->requireSqlite($platform);
        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('trending_story')->hasColumn('user_id'),
            'Trending stories already belong to no user.',
        );

        $this->deleteTrendingRows();
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->downMysql();

            return;
        }
        $this->requireSqlite($platform);
        $this->downSqlite();
    }

    /** MySQL commits each DDL statement, so up checks what a failed earlier run left done. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function deleteTrendingRows(): void
    {
        foreach (self::TABLES_IN_FOREIGN_KEY_ORDER as $table) {
            $this->addSql('DELETE FROM ' . $table);
        }
    }

    private function upMysql(): void
    {
        $this->addSql(
            'ALTER TABLE trending_story ADD user_id INT NOT NULL',
        );
        $this->addSql(
            'ALTER TABLE trending_story ADD CONSTRAINT FK_39933060A76ED395'
            . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
        );
        $this->addSql('CREATE INDEX idx_trending_story_user ON trending_story (user_id)');
        $this->addSql(
            'ALTER TABLE trending_story_placement ADD user_id INT NOT NULL, DROP PRIMARY KEY,'
            . ' ADD PRIMARY KEY (user_id, time_window, entry_id)',
        );
        $this->addSql(
            'ALTER TABLE trending_story_placement ADD CONSTRAINT FK_66654322A76ED395'
            . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
        );
        $this->addSql('CREATE INDEX IDX_66654322A76ED395 ON trending_story_placement (user_id)');
        $this->addSql(
            'ALTER TABLE trending_snapshot ADD active_feed_count INT NOT NULL, ADD user_id INT NOT NULL,'
            . ' DROP PRIMARY KEY, ADD PRIMARY KEY (user_id, time_window)',
        );
        $this->addSql(
            'ALTER TABLE trending_snapshot ADD CONSTRAINT FK_1EC6AF27A76ED395'
            . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
        );
        $this->addSql('CREATE INDEX IDX_1EC6AF27A76ED395 ON trending_snapshot (user_id)');
    }

    private function downMysql(): void
    {
        $this->addSql('ALTER TABLE trending_snapshot DROP FOREIGN KEY FK_1EC6AF27A76ED395');
        $this->addSql('DROP INDEX IDX_1EC6AF27A76ED395 ON trending_snapshot');
        $this->addSql(
            'ALTER TABLE trending_snapshot DROP PRIMARY KEY, DROP active_feed_count, DROP user_id,'
            . ' ADD PRIMARY KEY (time_window)',
        );
        $this->addSql('ALTER TABLE trending_story_placement DROP FOREIGN KEY FK_66654322A76ED395');
        $this->addSql('DROP INDEX IDX_66654322A76ED395 ON trending_story_placement');
        $this->addSql(
            'ALTER TABLE trending_story_placement DROP PRIMARY KEY, DROP user_id,'
            . ' ADD PRIMARY KEY (time_window, entry_id)',
        );
        $this->addSql('ALTER TABLE trending_story DROP FOREIGN KEY FK_39933060A76ED395');
        $this->addSql('DROP INDEX idx_trending_story_user ON trending_story');
        $this->addSql('ALTER TABLE trending_story DROP user_id');
    }

    /** The tables are empty, so each is dropped and created anew; the entry search triggers sit on other tables. */
    private function upSqlite(): void
    {
        $this->addSql('DROP TABLE trending_story_placement');
        $this->addSql('DROP TABLE trending_snapshot');
        $this->addSql('DROP TABLE trending_story');
        $this->addSql(
            'CREATE TABLE trending_story (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL, first_joined_at DATETIME NOT NULL,'
            . ' last_joined_at DATETIME NOT NULL, user_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_39933060A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
        );
        $this->addSql('CREATE INDEX idx_trending_story_user ON trending_story (user_id)');
        $this->addSql(
            'CREATE TABLE trending_story_placement (time_window VARCHAR(8) NOT NULL, entry_id INTEGER NOT NULL,'
            . ' story_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (user_id, time_window, entry_id),'
            . ' CONSTRAINT FK_66654322BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id)'
            . ' ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_66654322AA5D4036 FOREIGN KEY (story_id) REFERENCES trending_story (id)'
            . ' ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_66654322A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
        );
        $this->addSql('CREATE INDEX IDX_66654322AA5D4036 ON trending_story_placement (story_id)');
        $this->addSql('CREATE INDEX IDX_66654322BA364942 ON trending_story_placement (entry_id)');
        $this->addSql('CREATE INDEX IDX_66654322A76ED395 ON trending_story_placement (user_id)');
        $this->addSql(
            'CREATE TABLE trending_snapshot (time_window VARCHAR(8) NOT NULL, built_at DATETIME NOT NULL,'
            . ' entry_count INTEGER NOT NULL, story_count INTEGER NOT NULL, duration_ms INTEGER NOT NULL,'
            . ' peak_memory_bytes BIGINT NOT NULL, active_feed_count INTEGER NOT NULL, user_id INTEGER NOT NULL,'
            . ' PRIMARY KEY (user_id, time_window),'
            . ' CONSTRAINT FK_1EC6AF27A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
        );
        $this->addSql('CREATE INDEX IDX_1EC6AF27A76ED395 ON trending_snapshot (user_id)');
    }

    private function downSqlite(): void
    {
        $this->addSql('DROP TABLE trending_story_placement');
        $this->addSql('DROP TABLE trending_snapshot');
        $this->addSql('DROP TABLE trending_story');
        $this->addSql(
            'CREATE TABLE trending_story (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL, first_joined_at DATETIME NOT NULL,'
            . ' last_joined_at DATETIME NOT NULL)',
        );
        $this->addSql(
            'CREATE TABLE trending_story_placement (time_window VARCHAR(8) NOT NULL, entry_id INTEGER NOT NULL,'
            . ' story_id INTEGER NOT NULL, PRIMARY KEY (time_window, entry_id),'
            . ' CONSTRAINT FK_66654322BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_66654322AA5D4036 FOREIGN KEY (story_id) REFERENCES trending_story (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
        );
        $this->addSql('CREATE INDEX IDX_66654322BA364942 ON trending_story_placement (entry_id)');
        $this->addSql('CREATE INDEX IDX_66654322AA5D4036 ON trending_story_placement (story_id)');
        $this->addSql(
            'CREATE TABLE trending_snapshot (time_window VARCHAR(8) NOT NULL, built_at DATETIME NOT NULL,'
            . ' entry_count INTEGER NOT NULL, story_count INTEGER NOT NULL, duration_ms INTEGER NOT NULL,'
            . ' peak_memory_bytes BIGINT NOT NULL, PRIMARY KEY (time_window))',
        );
    }

    private function requireSqlite(object $platform): void
    {
        if (!$platform instanceof SQLitePlatform) {
            throw new \RuntimeException('Unsupported database platform for the Trending user migration.');
        }
    }
}
