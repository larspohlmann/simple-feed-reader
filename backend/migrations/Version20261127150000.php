<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127150000 extends AbstractMigration
{
    private const string TABLE = 'entry_state';
    private const string COLUMN = 'liked_at';
    private const string INDEX = 'idx_entry_state_user_liked_at';

    public function getDescription(): string
    {
        return 'A local like on the entry state, indexed for the Liked view (#2587).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable(self::TABLE)->hasColumn(self::COLUMN), 'Already applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN %s DATETIME DEFAULT NULL', self::TABLE, self::COLUMN));
        $this->addSql(\sprintf('CREATE INDEX %s ON %s (user_id, %s)', self::INDEX, self::TABLE, self::COLUMN));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable(self::TABLE)->hasColumn(self::COLUMN), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? \sprintf('DROP INDEX %s ON %s', self::INDEX, self::TABLE)
            : \sprintf('DROP INDEX %s', self::INDEX));
        $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', self::TABLE, self::COLUMN));
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
