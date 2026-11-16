<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** An entry flagged in two stories of one window keeps the lower story id: the new key allows only one. */
final class Version20261116220000 extends AbstractMigration
{
    private const array FLAG_BY_WINDOW = ['day' => 'in_day', 'week' => 'in_week'];
    private const string PLACEMENTS_FROM_FLAGS = 'INSERT INTO trending_story_placement (time_window, entry_id, story_id)'
        . " SELECT '%1\$s', member.entry_id, MIN(member.story_id) FROM trending_story_entry member"
        . ' WHERE member.%2$s = 1 AND NOT EXISTS (SELECT 1 FROM trending_story_placement placed'
        . " WHERE placed.time_window = '%1\$s' AND placed.entry_id = member.entry_id)"
        . ' GROUP BY member.entry_id';
    private const string FLAGS_FROM_PLACEMENTS = 'UPDATE trending_story_entry SET %2$s = CASE WHEN EXISTS (SELECT 1'
        . " FROM trending_story_placement placed WHERE placed.time_window = '%1\$s'"
        . ' AND placed.entry_id = trending_story_entry.entry_id AND placed.story_id = trending_story_entry.story_id)'
        . ' THEN 1 ELSE 0 END';

    public function getDescription(): string
    {
        return 'Trending keeps a story\'s window placement as rows keyed by window and entry (#2272)';
    }

    public function up(Schema $schema): void
    {
        $flagByWindow = $this->remainingFlagByWindow($schema);
        $this->skipIf([] === $flagByWindow, 'Trending placement is already kept as rows.');

        if (!$schema->hasTable('trending_story_placement')) {
            $this->createPlacements();
        }
        foreach ($flagByWindow as $window => $flag) {
            $this->addSql(\sprintf(self::PLACEMENTS_FROM_FLAGS, $window, $flag));
        }
        $this->dropFlags(array_values($flagByWindow));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('trending_story_placement'), 'Trending placement is still kept as flags.');

        $this->addFlags();
        foreach (self::FLAG_BY_WINDOW as $window => $flag) {
            $this->addSql(\sprintf(self::FLAGS_FROM_PLACEMENTS, $window, $flag));
        }
        $this->addSql('DROP TABLE trending_story_placement');
    }

    /** MySQL commits each DDL statement, so up checks what a failed earlier run left done. */
    public function isTransactional(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    private function remainingFlagByWindow(Schema $schema): array
    {
        $members = $schema->getTable('trending_story_entry');

        return array_filter(self::FLAG_BY_WINDOW, $members->hasColumn(...));
    }

    private function createPlacements(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(
                'CREATE TABLE trending_story_placement (time_window VARCHAR(8) NOT NULL, entry_id INT NOT NULL,'
                . ' story_id INT NOT NULL, INDEX IDX_66654322BA364942 (entry_id), INDEX IDX_66654322AA5D4036 (story_id),'
                . ' PRIMARY KEY (time_window, entry_id),'
                . ' CONSTRAINT FK_66654322BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE,'
                . ' CONSTRAINT FK_66654322AA5D4036 FOREIGN KEY (story_id) REFERENCES trending_story (id)'
                . ' ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
            );

            return;
        }
        $this->requireSqlite($platform);
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
    }

    /** Neither flag carries an index or a key, so SQLite drops them in place and the entry search triggers stay. */
    /** @param list<string> $flags */
    private function dropFlags(array $flags): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $drops = array_map(static fn (string $flag): string => 'DROP ' . $flag, $flags);
            $this->addSql('ALTER TABLE trending_story_entry ' . implode(', ', $drops));

            return;
        }
        $this->requireSqlite($platform);
        foreach ($flags as $flag) {
            $this->addSql('ALTER TABLE trending_story_entry DROP COLUMN ' . $flag);
        }
    }

    private function addFlags(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(
                'ALTER TABLE trending_story_entry ADD in_day TINYINT DEFAULT 0 NOT NULL,'
                . ' ADD in_week TINYINT DEFAULT 0 NOT NULL',
            );

            return;
        }
        $this->requireSqlite($platform);
        $this->addSql('ALTER TABLE trending_story_entry ADD COLUMN in_day BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE trending_story_entry ADD COLUMN in_week BOOLEAN DEFAULT 0 NOT NULL');
    }

    private function requireSqlite(object $platform): void
    {
        if (!$platform instanceof SQLitePlatform) {
            throw new \RuntimeException('Unsupported database platform for the Trending placement migration.');
        }
    }
}
