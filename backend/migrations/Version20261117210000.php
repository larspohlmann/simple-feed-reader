<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117210000 extends AbstractMigration
{
    private const string TABLE = 'social_notification';
    private const string COLUMN = 'subject_context_on_comment';

    public function getDescription(): string
    {
        return 'Mark notifications about your comment; comment_reply becomes reply (#2304).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfUnsupportedPlatform();
        if (!$schema->getTable(self::TABLE)->hasColumn(self::COLUMN)) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN %s BOOLEAN DEFAULT 0 NOT NULL', self::TABLE, self::COLUMN));
        }
        $this->addSql(\sprintf(
            "UPDATE %s SET kind = 'reply', %s = 1 WHERE kind = 'comment_reply'",
            self::TABLE,
            self::COLUMN,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable(self::TABLE)->hasColumn(self::COLUMN), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql(\sprintf(
            "UPDATE %s SET kind = 'comment_reply' WHERE kind = 'reply' AND %s = 1",
            self::TABLE,
            self::COLUMN,
        ));
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
