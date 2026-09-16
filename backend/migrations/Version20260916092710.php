<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the run throttle columns for provider rate-limit handling (#947):
 * retry_not_before gates the next tick, reduced_concurrency lowers the wave.
 * PLATFORM-AWARE DDL — tests build schema from ORM metadata and never run a
 * migration, so a dialect error here is caught only by CI's migrate-from-empty leg.
 */
final class Version20260916092710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recommendation_run throttle columns for 429 retry and reduced concurrency (#947).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->assertSupportedPlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run ADD retry_not_before DATETIME DEFAULT NULL, ADD reduced_concurrency INT DEFAULT NULL');

            return;
        }

        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN retry_not_before DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN reduced_concurrency INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->assertSupportedPlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run DROP retry_not_before, DROP reduced_concurrency');

            return;
        }

        $this->addSql('ALTER TABLE recommendation_run DROP COLUMN retry_not_before');
        $this->addSql('ALTER TABLE recommendation_run DROP COLUMN reduced_concurrency');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function assertSupportedPlatform(): AbstractMySQLPlatform|SQLitePlatform
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SQLitePlatform),
            sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        /** @var AbstractMySQLPlatform|SQLitePlatform $platform */
        return $platform;
    }
}
