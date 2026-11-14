<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114100000 extends AbstractMigration
{
    private const string TABLE = 'entry';
    private const string SCORE = 'score';
    private const string COMMENT_COUNT = 'comment_count';

    public function getDescription(): string
    {
        return 'Store a discussion\'s score and comment count on the entry (#2217).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable(self::TABLE)->hasColumn(self::SCORE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();
        foreach ([self::SCORE, self::COMMENT_COUNT] as $column) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN %s INT DEFAULT NULL', self::TABLE, $column));
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable(self::TABLE)->hasColumn(self::SCORE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        foreach ([self::SCORE, self::COMMENT_COUNT] as $column) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN %s', self::TABLE, $column));
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
