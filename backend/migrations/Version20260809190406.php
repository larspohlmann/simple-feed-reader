<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Each AI configuration can ask the provider not to reason (#323).
 *
 * `user_ai_settings` gains `suppress_reasoning`, default 1: ranking never
 * needs a thinking phase, so existing rows suppress like new ones. A strict
 * endpoint turns it off per configuration.
 */
final class Version20260809190406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-config suppress_reasoning to user_ai_settings (#323)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_ai_settings ADD suppress_reasoning TINYINT(1) DEFAULT 1 NOT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN suppress_reasoning BOOLEAN DEFAULT 1 NOT NULL');

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
            $this->addSql('ALTER TABLE user_ai_settings DROP suppress_reasoning');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN suppress_reasoning');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }
}
