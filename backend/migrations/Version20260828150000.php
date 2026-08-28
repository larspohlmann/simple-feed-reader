<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phrase saved searches (#702): a per-search flag for the exact-phrase mode
 * (the raw query wrapped in double quotes), beside the existing whole-word one.
 *
 * Hand-written and platform-aware, like the other saved_search migrations: the
 * test suite builds its schema from ORM metadata and never runs this file; only
 * CI's migrate-from-empty leg does, on both SQLite and MySQL. The mode is part
 * of a saved search's identity, so the uniqueness index widens to carry it —
 * `"climate change"` and `climate change` are two distinct saved searches.
 */
final class Version20260828150000 extends AbstractMigration
{
    private const string OLD_INDEX = 'uniq_saved_search_user_term_word';
    private const string NEW_INDEX = 'uniq_saved_search_user_term_mode';

    public function getDescription(): string
    {
        return 'Saved searches: add the phrase (exact-phrase) mode flag (#702).';
    }

    public function up(Schema $schema): void
    {
        $mysql = $this->assertSupportedPlatform();

        $this->addSql(
            $mysql
                ? 'ALTER TABLE saved_search ADD phrase TINYINT(1) DEFAULT 0 NOT NULL'
                : 'ALTER TABLE saved_search ADD COLUMN phrase BOOLEAN DEFAULT 0 NOT NULL',
        );

        $this->addSql($mysql
            ? \sprintf('DROP INDEX %s ON saved_search', self::OLD_INDEX)
            : \sprintf('DROP INDEX %s', self::OLD_INDEX));
        $this->addSql(\sprintf(
            'CREATE UNIQUE INDEX %s ON saved_search (user_id, term, whole_word, phrase)',
            self::NEW_INDEX,
        ));
    }

    public function down(Schema $schema): void
    {
        $mysql = $this->assertSupportedPlatform();

        $this->addSql($mysql
            ? \sprintf('DROP INDEX %s ON saved_search', self::NEW_INDEX)
            : \sprintf('DROP INDEX %s', self::NEW_INDEX));
        $this->addSql(\sprintf(
            'CREATE UNIQUE INDEX %s ON saved_search (user_id, term, whole_word)',
            self::OLD_INDEX,
        ));

        $this->addSql('ALTER TABLE saved_search DROP phrase');
    }

    private function assertSupportedPlatform(): bool
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        $this->abortIf(
            !$mysql && !($platform instanceof SQLitePlatform),
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $mysql;
    }
}
