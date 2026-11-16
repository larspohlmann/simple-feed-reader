<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Down loses every follow: the per-window schema has nowhere to keep them. */
final class Version20261116180000 extends AbstractMigration
{
    private const string MYSQL_TABLE_OPTIONS = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'
        . ' ENGINE = InnoDB';
    private const string FOLLOW_COLUMNS = 'followed_at DATETIME NOT NULL, last_opened_at DATETIME NOT NULL';
    private const string HIDE_COLUMNS = 'hidden_at DATETIME NOT NULL';
    private const string JOIN_TIME = 'COALESCE((SELECT snapshot.built_at FROM trending_snapshot snapshot'
        . ' WHERE snapshot.time_window = story.time_window), story.last_seen)';
    private const string HIDES_FROM_HIDDEN_ENTRIES_OF_MEMBERS = 'INSERT INTO trending_story_hide'
        . ' (user_id, story_id, hidden_at) SELECT hidden.user_id, member.story_id, MIN(hidden.hidden_at)'
        . ' FROM trending_hidden_entry hidden JOIN %s member ON member.entry_id = hidden.entry_id'
        . ' WHERE NOT EXISTS (SELECT 1 FROM trending_story_hide existing'
        . ' WHERE existing.user_id = hidden.user_id AND existing.story_id = member.story_id)'
        . ' GROUP BY hidden.user_id, member.story_id';
    private const string HIDDEN_ENTRIES_FROM_HIDES = 'INSERT INTO trending_hidden_entry (hidden_at, user_id, entry_id)'
        . ' SELECT MIN(hide.hidden_at), hide.user_id, member.entry_id FROM trending_story_hide hide'
        . ' JOIN trending_story_entry member ON member.story_id = hide.story_id'
        . ' GROUP BY hide.user_id, member.entry_id';
    private const string WINDOW_OF_STORY = "CASE WHEN EXISTS (SELECT 1 FROM trending_story_entry member"
        . " WHERE member.story_id = story.id AND member.in_day = 1) THEN 'day' ELSE 'week' END";

    public function getDescription(): string
    {
        return 'Trending stories shared by both windows, with history, follows and hides by story (#2128)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->hasTable('trending_hidden_entry')
            && !$schema->getTable('trending_story')->hasColumn('time_window'),
            'Trending stories are already shared by both windows.',
        );

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql($schema);

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the Trending story migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('trending_story_follow'), 'Trending stories are still per window.');

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->downMySql();

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->downSqlite();

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the Trending story migration.');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    /** MySQL commits each DDL statement, so every step checks what a failed earlier run left done. */
    private function upMySql(Schema $schema): void
    {
        if (!$schema->hasTable('trending_story_follow')) {
            $this->createMySqlStoryUserTable('trending_story_follow', self::FOLLOW_COLUMNS, '54E0250');
        }
        if (!$schema->hasTable('trending_story_hide')) {
            $this->createMySqlStoryUserTable('trending_story_hide', self::HIDE_COLUMNS, '6174BFCF');
        }
        if ($schema->getTable('trending_story')->hasColumn('time_window')) {
            $this->seedMySqlJoinTimes($schema);
        }
        if ($schema->hasTable('trending_hidden_entry')) {
            $this->addSql(\sprintf(self::HIDES_FROM_HIDDEN_ENTRIES_OF_MEMBERS, 'trending_story_entry'));
            $this->addSql('DROP TABLE trending_hidden_entry');
        }
        if ($schema->getTable('trending_story')->hasColumn('time_window')) {
            $this->addSql('ALTER TABLE trending_story DROP INDEX idx_trending_story_window, DROP time_window');
        }
    }

    private function seedMySqlJoinTimes(Schema $schema): void
    {
        if (!$schema->getTable('trending_story')->hasColumn('first_joined_at')) {
            $this->addSql(
                'ALTER TABLE trending_story ADD first_joined_at DATETIME DEFAULT NULL,'
                . ' ADD last_joined_at DATETIME DEFAULT NULL',
            );
        }
        if (!$schema->getTable('trending_story_entry')->hasColumn('joined_at')) {
            $this->addSql(
                'ALTER TABLE trending_story_entry ADD joined_at DATETIME DEFAULT NULL,'
                . ' ADD in_day TINYINT DEFAULT 0 NOT NULL, ADD in_week TINYINT DEFAULT 0 NOT NULL',
            );
        }
        $this->addSql(\sprintf(
            'UPDATE trending_story story SET story.first_joined_at = %1$s, story.last_joined_at = %1$s',
            self::JOIN_TIME,
        ));
        $this->addSql(
            'UPDATE trending_story_entry member JOIN trending_story story ON story.id = member.story_id'
            . " SET member.joined_at = story.first_joined_at, member.in_day = story.time_window = 'day',"
            . " member.in_week = story.time_window = 'week'",
        );
        $this->addSql(
            'ALTER TABLE trending_story MODIFY first_joined_at DATETIME NOT NULL,'
            . ' MODIFY last_joined_at DATETIME NOT NULL',
        );
        $this->addSql('ALTER TABLE trending_story_entry MODIFY joined_at DATETIME NOT NULL');
    }

    private function createMySqlStoryUserTable(string $table, string $columns, string $keySuffix): void
    {
        $this->addSql(\sprintf(
            'CREATE TABLE %1$s (%2$s, user_id INT NOT NULL, story_id INT NOT NULL,'
            . ' INDEX IDX_%3$sA76ED395 (user_id), INDEX IDX_%3$sAA5D4036 (story_id),'
            . ' PRIMARY KEY (user_id, story_id),'
            . ' CONSTRAINT FK_%3$sA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE,'
            . ' CONSTRAINT FK_%3$sAA5D4036 FOREIGN KEY (story_id) REFERENCES trending_story (id) ON DELETE CASCADE'
            . ') %4$s',
            $table,
            $columns,
            $keySuffix,
            self::MYSQL_TABLE_OPTIONS,
        ));
    }

    /**
     * The *_new tables reference trending_story_new, so dropping the old story table cascades into nothing, and the
     * rename rewrites those references to trending_story.
     */
    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE trending_story_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                first_seen DATETIME NOT NULL,
                last_seen DATETIME NOT NULL,
                first_joined_at DATETIME NOT NULL,
                last_joined_at DATETIME NOT NULL
            )
            SQL);
        $this->addSql(\sprintf(
            'INSERT INTO trending_story_new (id, first_seen, last_seen, first_joined_at, last_joined_at)'
            . ' SELECT story.id, story.first_seen, story.last_seen, %1$s, %1$s FROM trending_story story',
            self::JOIN_TIME,
        ));
        $this->createSqliteMembers('trending_story_entry_new', 'trending_story_new', <<<'SQL'
            joined_at DATETIME NOT NULL,
            in_day BOOLEAN DEFAULT 0 NOT NULL,
            in_week BOOLEAN DEFAULT 0 NOT NULL,
            SQL);
        $this->addSql(
            'INSERT INTO trending_story_entry_new (feed_id, story_id, entry_id, article_site, shared_link_id,'
            . ' joined_at, in_day, in_week)'
            . ' SELECT member.feed_id, member.story_id, member.entry_id, member.article_site, member.shared_link_id,'
            . " joined.first_joined_at, story.time_window = 'day', story.time_window = 'week'"
            . ' FROM trending_story_entry member JOIN trending_story story ON story.id = member.story_id'
            . ' JOIN trending_story_new joined ON joined.id = member.story_id',
        );
        $this->createSqliteStoryUserTable('trending_story_follow', self::FOLLOW_COLUMNS, '54E0250');
        $this->createSqliteStoryUserTable('trending_story_hide', self::HIDE_COLUMNS, '6174BFCF');
        $this->addSql(\sprintf(self::HIDES_FROM_HIDDEN_ENTRIES_OF_MEMBERS, 'trending_story_entry_new'));
        $this->addSql('DROP TABLE trending_hidden_entry');
        $this->addSql('DROP TABLE trending_story_entry');
        $this->addSql('DROP TABLE trending_story');
        $this->addSql('ALTER TABLE trending_story_new RENAME TO trending_story');
        $this->addSql('ALTER TABLE trending_story_entry_new RENAME TO trending_story_entry');
        $this->createSqliteMemberIndexes();
        $this->createSqliteStoryUserIndexes('trending_story_follow', '54E0250');
        $this->createSqliteStoryUserIndexes('trending_story_hide', '6174BFCF');
    }

    private function downMySql(): void
    {
        $this->createMySqlHiddenEntries();
        $this->addSql(self::HIDDEN_ENTRIES_FROM_HIDES);
        $this->addSql('ALTER TABLE trending_story ADD time_window VARCHAR(8) DEFAULT NULL');
        $this->addSql(\sprintf('UPDATE trending_story story SET story.time_window = %s', self::WINDOW_OF_STORY));
        $this->addSql(
            'ALTER TABLE trending_story MODIFY time_window VARCHAR(8) NOT NULL, DROP first_joined_at,'
            . ' DROP last_joined_at, ADD INDEX idx_trending_story_window (time_window)',
        );
        $this->addSql('ALTER TABLE trending_story_entry DROP joined_at, DROP in_day, DROP in_week');
        $this->addSql('DROP TABLE trending_story_follow');
        $this->addSql('DROP TABLE trending_story_hide');
    }

    private function createMySqlHiddenEntries(): void
    {
        $this->addSql(\sprintf(
            'CREATE TABLE trending_hidden_entry (hidden_at DATETIME NOT NULL, user_id INT NOT NULL,'
            . ' entry_id INT NOT NULL, INDEX IDX_D6B1BD7CA76ED395 (user_id), INDEX IDX_D6B1BD7CBA364942 (entry_id),'
            . ' PRIMARY KEY (user_id, entry_id)) %s',
            self::MYSQL_TABLE_OPTIONS,
        ));
        $this->addSql(
            'ALTER TABLE trending_hidden_entry ADD CONSTRAINT FK_D6B1BD7CA76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE trending_hidden_entry ADD CONSTRAINT FK_D6B1BD7CBA364942 FOREIGN KEY (entry_id)'
            . ' REFERENCES entry (id) ON DELETE CASCADE',
        );
    }

    private function downSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE trending_story_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                time_window VARCHAR(8) NOT NULL,
                first_seen DATETIME NOT NULL,
                last_seen DATETIME NOT NULL
            )
            SQL);
        $this->addSql(\sprintf(
            'INSERT INTO trending_story_old (id, time_window, first_seen, last_seen)'
            . ' SELECT story.id, %s, story.first_seen, story.last_seen FROM trending_story story',
            self::WINDOW_OF_STORY,
        ));
        $this->createSqliteMembers('trending_story_entry_old', 'trending_story_old', '');
        $this->addSql(
            'INSERT INTO trending_story_entry_old (feed_id, story_id, entry_id, article_site, shared_link_id)'
            . ' SELECT feed_id, story_id, entry_id, article_site, shared_link_id FROM trending_story_entry',
        );
        $this->addSql(<<<'SQL'
            CREATE TABLE trending_hidden_entry (
                hidden_at DATETIME NOT NULL,
                user_id INTEGER NOT NULL,
                entry_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, entry_id),
                CONSTRAINT FK_D6B1BD7CA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_D6B1BD7CBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql(self::HIDDEN_ENTRIES_FROM_HIDES);
        $this->addSql('CREATE INDEX IDX_D6B1BD7CA76ED395 ON trending_hidden_entry (user_id)');
        $this->addSql('CREATE INDEX IDX_D6B1BD7CBA364942 ON trending_hidden_entry (entry_id)');
        $this->addSql('DROP TABLE trending_story_follow');
        $this->addSql('DROP TABLE trending_story_hide');
        $this->addSql('DROP TABLE trending_story_entry');
        $this->addSql('DROP TABLE trending_story');
        $this->addSql('ALTER TABLE trending_story_old RENAME TO trending_story');
        $this->addSql('ALTER TABLE trending_story_entry_old RENAME TO trending_story_entry');
        $this->addSql('CREATE INDEX idx_trending_story_window ON trending_story (time_window)');
        $this->createSqliteMemberIndexes();
    }

    private function createSqliteMembers(string $table, string $storyTable, string $placementColumns): void
    {
        $this->addSql(\sprintf(<<<'SQL'
            CREATE TABLE %1$s (
                feed_id INTEGER NOT NULL,
                story_id INTEGER NOT NULL,
                entry_id INTEGER NOT NULL,
                article_site VARCHAR(255) DEFAULT NULL,
                shared_link_id INTEGER DEFAULT NULL,
                %3$s
                PRIMARY KEY (story_id, entry_id),
                CONSTRAINT FK_8C835665AA5D4036 FOREIGN KEY (story_id) REFERENCES %2$s (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_8C835665BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_8C8356658C730EC6 FOREIGN KEY (shared_link_id) REFERENCES shared_link (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL, $table, $storyTable, $placementColumns));
    }

    private function createSqliteMemberIndexes(): void
    {
        $this->addSql('CREATE INDEX IDX_8C835665AA5D4036 ON trending_story_entry (story_id)');
        $this->addSql('CREATE INDEX IDX_8C835665BA364942 ON trending_story_entry (entry_id)');
        $this->addSql('CREATE INDEX idx_trending_story_entry_feed ON trending_story_entry (feed_id)');
        $this->addSql('CREATE INDEX IDX_8C8356658C730EC6 ON trending_story_entry (shared_link_id)');
    }

    private function createSqliteStoryUserTable(string $table, string $columns, string $keySuffix): void
    {
        $this->addSql(\sprintf(
            'CREATE TABLE %1$s (%2$s, user_id INTEGER NOT NULL, story_id INTEGER NOT NULL,'
            . ' PRIMARY KEY (user_id, story_id),'
            . ' CONSTRAINT FK_%3$sA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_%3$sAA5D4036 FOREIGN KEY (story_id) REFERENCES trending_story_new (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            $table,
            $columns,
            $keySuffix,
        ));
    }

    private function createSqliteStoryUserIndexes(string $table, string $keySuffix): void
    {
        $this->addSql(\sprintf('CREATE INDEX IDX_%2$sA76ED395 ON %1$s (user_id)', $table, $keySuffix));
        $this->addSql(\sprintf('CREATE INDEX IDX_%2$sAA5D4036 ON %1$s (story_id)', $table, $keySuffix));
    }
}
