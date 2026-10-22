<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    private const string COLUMN = 'include_in_saved_searches';

    public function getDescription(): string
    {
        return 'Keep feeds and social networks out of saved searches:'
            . ' subscription.include_in_saved_searches and saved_search_network_inclusion (#1742).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('subscription')->hasColumn(self::COLUMN), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->upOnMySql();

            return;
        }

        $this->upOnSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('subscription')->hasColumn(self::COLUMN), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('ALTER TABLE subscription DROP COLUMN ' . self::COLUMN);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upOnMySql(): void
    {
        $this->addSql('ALTER TABLE subscription ADD include_in_saved_searches TINYINT(1) DEFAULT 1 NOT NULL');
    }

    private function upOnSqlite(): void
    {
        $this->addSql('ALTER TABLE subscription ADD COLUMN include_in_saved_searches BOOLEAN DEFAULT 1 NOT NULL');
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
