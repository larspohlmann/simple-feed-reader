<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_digest_settings.include_for_you_stream opts the digest into the For you stream (#2582)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_digest_settings')->hasColumn('include_for_you_stream'),
            'user_digest_settings.include_for_you_stream already exists.',
        );

        if ($this->mysql()) {
            $this->addSql('ALTER TABLE user_digest_settings ADD include_for_you_stream TINYINT DEFAULT 0 NOT NULL');

            return;
        }

        $this->addSql('ALTER TABLE user_digest_settings ADD COLUMN include_for_you_stream BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_digest_settings')->hasColumn('include_for_you_stream'),
            'user_digest_settings.include_for_you_stream does not exist.',
        );

        if ($this->mysql()) {
            $this->addSql('ALTER TABLE user_digest_settings DROP include_for_you_stream');

            return;
        }

        $this->addSql('ALTER TABLE user_digest_settings DROP COLUMN include_for_you_stream');
    }

    /** MySQL commits each DDL statement, so a wrapping transaction protects nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
