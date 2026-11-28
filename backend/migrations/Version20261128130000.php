<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128130000 extends AbstractMigration
{
    private const array TABLES = ['subscription', 'tag'];
    private const string COLUMN = 'include_in_your_stream';

    public function getDescription(): string
    {
        return 'Your stream inclusion switch on subscriptions and tags, seeded from For you (#2601).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable(self::TABLES[0])->hasColumn(self::COLUMN), 'Already applied.');

        $this->abortIfUnsupportedPlatform();
        $type = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'TINYINT(1)' : 'BOOLEAN';
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN %s %s DEFAULT 1 NOT NULL', $table, self::COLUMN, $type));
            $this->addSql(\sprintf('UPDATE %s SET %s = include_in_for_you', $table, self::COLUMN));
        }
        $this->addSql(
            "INSERT INTO network_exclusion (user_id, network, surface) "
            . "SELECT user_id, network, 'your-stream' FROM network_exclusion WHERE surface = 'for-you'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable(self::TABLES[0])->hasColumn(self::COLUMN), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql("DELETE FROM network_exclusion WHERE surface = 'your-stream'");
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', $table, self::COLUMN));
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
