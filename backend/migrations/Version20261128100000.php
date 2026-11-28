<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128100000 extends AbstractMigration
{
    private const string TABLE = 'user_recommendation_settings';
    private const string ENABLED = 'for_you_stream_enabled';
    private const string PRECISION = 'for_you_stream_precision';

    public function getDescription(): string
    {
        return 'For you stream switch and precision on the recommendation settings (#2594).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable(self::TABLE)->hasColumn(self::ENABLED), 'Already applied.');

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql(\sprintf(
                "ALTER TABLE %s ADD %s TINYINT(1) DEFAULT 1 NOT NULL, ADD %s VARCHAR(10) DEFAULT 'wide' NOT NULL",
                self::TABLE,
                self::ENABLED,
                self::PRECISION,
            ));

            return;
        }

        $this->abortIfNotSqlite();
        $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN %s BOOLEAN DEFAULT 1 NOT NULL', self::TABLE, self::ENABLED));
        $this->addSql(\sprintf(
            "ALTER TABLE %s ADD COLUMN %s VARCHAR(10) DEFAULT 'wide' NOT NULL",
            self::TABLE,
            self::PRECISION,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable(self::TABLE)->hasColumn(self::ENABLED), 'Not applied.');

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql(\sprintf(
                'ALTER TABLE %s DROP COLUMN %s, DROP COLUMN %s',
                self::TABLE,
                self::ENABLED,
                self::PRECISION,
            ));

            return;
        }

        $this->abortIfNotSqlite();
        $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', self::TABLE, self::ENABLED));
        $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', self::TABLE, self::PRECISION));
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function abortIfNotSqlite(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
