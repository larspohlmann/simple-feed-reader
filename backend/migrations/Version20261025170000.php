<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compare tag names by bytes on MySQL (utf8mb4_0900_bin): an accent makes a different name, '
            . 'case still does not (#1805).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->assertSupportedPlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE tag CHANGE name name VARCHAR(100) NOT NULL COLLATE `utf8mb4_0900_bin`');
    }

    public function down(Schema $schema): void
    {
        if (!$this->assertSupportedPlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE tag MODIFY name VARCHAR(100) NOT NULL');
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
