<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Each AI configuration can send a run's batch calls in parallel (#344).
 *
 * `user_ai_settings` gains `batch_concurrency`, default 1: sequential,
 * identical to the pre-#344 behaviour, so existing rows are unaffected until
 * an owner opts a connection in.
 */
final class Version20260810041059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-config batch_concurrency to user_ai_settings (#344)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_ai_settings ADD batch_concurrency INT DEFAULT 1 NOT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN batch_concurrency INTEGER DEFAULT 1 NOT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_ai_settings DROP batch_concurrency');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN batch_concurrency');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }
}
