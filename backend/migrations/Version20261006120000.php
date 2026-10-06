<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop a run\'s scoring protocol: a run no longer guards against its connection switching models (#1394).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('recommendation_run')->hasColumn('scoring_protocol'),
            'recommendation_run.scoring_protocol is already gone.',
        );

        $this->addSql(\sprintf('ALTER TABLE recommendation_run %s scoring_protocol', $this->mysql() ? 'DROP' : 'DROP COLUMN'));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('recommendation_run')->hasColumn('scoring_protocol'),
            'recommendation_run.scoring_protocol already exists.',
        );

        $this->addSql(\sprintf(
            'ALTER TABLE recommendation_run %s scoring_protocol VARCHAR(16) DEFAULT NULL',
            $this->mysql() ? 'ADD' : 'ADD COLUMN',
        ));
    }

    public function isTransactional(): bool
    {
        return false;
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
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
