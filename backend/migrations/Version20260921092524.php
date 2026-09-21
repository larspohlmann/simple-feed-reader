<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the image verification columns (#1109): image_checked_at (null =
 * awaiting the background verify) and image_verify_attempts (transient-failure
 * retry counter). Existing images are stamped with a sentinel checked_at so
 * only images written after this deploy enter the verification queue — the
 * shipped behavior is forward-only. PLATFORM-AWARE DDL — tests build schema
 * from ORM metadata and never run a migration, so a dialect error here is
 * caught only by CI's migrate-from-empty leg.
 */
final class Version20260921092524 extends AbstractMigration
{
    private const string GRANDFATHER_SENTINEL = '1970-01-01 00:00:00';

    public function getDescription(): string
    {
        return 'Add entry image verification columns and grandfather existing images (#1109).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->assertSupportedPlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry ADD image_checked_at DATETIME DEFAULT NULL, ADD image_verify_attempts INT DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE entry ADD COLUMN image_checked_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE entry ADD COLUMN image_verify_attempts INTEGER DEFAULT NULL');
        }

        $this->addSql(
            'UPDATE entry SET image_checked_at = :sentinel WHERE image_url IS NOT NULL',
            ['sentinel' => self::GRANDFATHER_SENTINEL],
        );
    }

    public function down(Schema $schema): void
    {
        $platform = $this->assertSupportedPlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry DROP image_checked_at, DROP image_verify_attempts');

            return;
        }

        $this->addSql('ALTER TABLE entry DROP COLUMN image_checked_at');
        $this->addSql('ALTER TABLE entry DROP COLUMN image_verify_attempts');
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
