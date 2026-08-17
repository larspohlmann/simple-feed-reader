<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user_ai_settings gains max_batch_size (#445): how many candidates one batch
 * of a connection's run may carry, split off `slow_model` because how fast an
 * endpoint answers and how long a list its model holds in order are different
 * properties.
 *
 * Nullable with no default — null means "no claim, the default stands", the
 * same reasoning RecommendationSettingsResolver::batchCeilingFor() already
 * applies to an absent connection.
 *
 * The backfill is what keeps this migration behaviour-preserving: every
 * connection already marked slow_model = 1 has been silently capped at 30.
 * Leaving those rows NULL on upgrade would raise them to the default of 45
 * and reintroduce the #437 failure, where a 4B local model given 45 entries
 * fell into a repetition loop on the ninth batch. 30 is written as a literal,
 * not a reference to a code constant: this migration records what the schema
 * was on the day it ran, and must not move when the code does.
 *
 * The hasColumn() guard covers the column only, not the backfill: an
 * installation whose schema already has max_batch_size — built by
 * doctrine:schema:update rather than this chain — returns before the UPDATE
 * ever runs, and any of its rows marked slow_model = 1 stay NULL rather than
 * 30. Not hardened; this is a personal-scale app and the guard's shape
 * matches every sibling migration's.
 *
 * PLATFORM-AWARE DDL for the reason Version20260814140000 records: DDL diffed
 * on one platform does not parse on the other, and the suite cannot catch it
 * because tests build their schema from ORM metadata, not this chain.
 */
final class Version20260816170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_ai_settings.max_batch_size and backfill slow connections (#445)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $settings = $schema->getTable('user_ai_settings');

        if ($settings->hasColumn('max_batch_size')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_ai_settings ADD max_batch_size INT DEFAULT NULL'
            : 'ALTER TABLE user_ai_settings ADD COLUMN max_batch_size INTEGER DEFAULT NULL');

        $this->addSql('UPDATE user_ai_settings SET max_batch_size = 30 WHERE slow_model = 1');
    }

    public function down(Schema $schema): void
    {
        $settings = $schema->getTable('user_ai_settings');

        if (!$settings->hasColumn('max_batch_size')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_ai_settings DROP max_batch_size'
            : 'ALTER TABLE user_ai_settings DROP COLUMN max_batch_size');
    }

    /**
     * Answers which of the two supported platforms this is running on, and
     * refuses any third: better a refusal than DDL invented for a platform
     * nobody tested.
     */
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
