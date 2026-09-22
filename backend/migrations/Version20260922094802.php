<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give the category table a binary collation, so MySQL compares its identity
 * columns by bytes like the application's identity() and like SQLite (#1111).
 * utf8mb4_unicode_ci applied Unicode canonical folding: precomposed "wählen"
 * (NFC, U+00E4) and decomposed "wählen" (NFD, a + U+0308) were one key to the
 * unique index, but the mb_strtolower'd canonical key keeps them distinct — so
 * ingest inserted a row the index rejected as a duplicate, aborting the refresh.
 * The conversion only splits previously merged keys, so it cannot itself
 * duplicate-fault. SQLite is already BINARY; nothing to do there.
 */
final class Version20260922094802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make category identity columns byte-comparing (utf8mb4_bin) to match the app identity (#1111).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->assertSupportedPlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE category CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    }

    public function down(Schema $schema): void
    {
        if (!$this->assertSupportedPlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE category CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
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
