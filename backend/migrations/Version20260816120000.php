<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * recommendation_run gains the provider it called and what that call cost
 * (#409): provider_host and model stamped at start, the four token counters
 * and the price banked by RecordedCall as each call settles.
 *
 * cost_nano_credits is BIGINT and nullable. Nullable because null means the
 * provider reported no price at all — a local model, or any run that predates
 * this migration — and 0 would claim those runs were free. BIGINT because a
 * credit is 1e9 nano-credits, so INT would overflow at 2.1 credits.
 *
 * PLATFORM-AWARE DDL for the reason Version20260814140000 records: DDL diffed
 * on one platform does not parse on the other, and the suite cannot catch it
 * because tests build their schema from ORM metadata, not this chain. SQLite
 * takes one ADD COLUMN per statement; MySQL takes them in one ALTER.
 */
final class Version20260816120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recommendation_run provider, token and cost columns (#409)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $run = $schema->getTable('recommendation_run');

        if ($run->hasColumn('provider_host')) {
            return;
        }

        if ($mysql) {
            $this->addSql(
                'ALTER TABLE recommendation_run '
                . 'ADD provider_host VARCHAR(255) DEFAULT NULL, '
                . 'ADD model VARCHAR(255) DEFAULT NULL, '
                . 'ADD prompt_tokens INT DEFAULT 0 NOT NULL, '
                . 'ADD completion_tokens INT DEFAULT 0 NOT NULL, '
                . 'ADD reasoning_tokens INT DEFAULT 0 NOT NULL, '
                . 'ADD cached_tokens INT DEFAULT 0 NOT NULL, '
                . 'ADD cost_nano_credits BIGINT DEFAULT NULL',
            );

            return;
        }

        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN provider_host VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN model VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN prompt_tokens INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN completion_tokens INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN reasoning_tokens INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN cached_tokens INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN cost_nano_credits BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $run = $schema->getTable('recommendation_run');

        if (!$run->hasColumn('provider_host')) {
            return;
        }

        $columns = [
            'provider_host',
            'model',
            'prompt_tokens',
            'completion_tokens',
            'reasoning_tokens',
            'cached_tokens',
            'cost_nano_credits',
        ];

        foreach ($columns as $column) {
            $this->addSql($mysql
                ? \sprintf('ALTER TABLE recommendation_run DROP %s', $column)
                : \sprintf('ALTER TABLE recommendation_run DROP COLUMN %s', $column));
        }
    }
}
