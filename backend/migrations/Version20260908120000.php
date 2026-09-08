<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces the expert batch-count override with a batch-size choice (#935):
 * user_recommendation_settings.batch_count → batch_size ('small'|'medium'|'large').
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 * The VARCHAR add and the DROP read the same on both dialects, so only the
 * `down` re-add of the integer column branches (SQLite's comparator treats INT
 * and INTEGER as a schema difference). Existing rows fall to 'medium', which is
 * the automatic behaviour the removed override defaulted to.
 */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Batch-size choice (#935): user_recommendation_settings.batch_count → batch_size.';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql("ALTER TABLE user_recommendation_settings ADD batch_size VARCHAR(10) DEFAULT 'medium' NOT NULL");
        $this->addSql('ALTER TABLE user_recommendation_settings DROP batch_count');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_recommendation_settings ADD batch_count INT DEFAULT NULL');
            $this->addSql('ALTER TABLE user_recommendation_settings DROP batch_size');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN batch_count INTEGER DEFAULT NULL');
            $this->addSql('ALTER TABLE user_recommendation_settings DROP COLUMN batch_size');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function assertSupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SQLitePlatform),
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
