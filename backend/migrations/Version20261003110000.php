<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** A run that was mid-distillation at the deploy now scores on without a profile. */
final class Version20261003110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation runs no longer record a distillation (#1351).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('recommendation_run')->hasColumn('distilled'),
            'recommendation_run.distilled is already gone.',
        );

        $this->addSql($this->mysql()
            ? 'ALTER TABLE recommendation_run DROP distilled'
            : 'ALTER TABLE recommendation_run DROP COLUMN distilled');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('recommendation_run')->hasColumn('distilled'),
            'recommendation_run.distilled already exists.',
        );

        $this->addSql($this->mysql()
            ? 'ALTER TABLE recommendation_run ADD distilled TINYINT(1) DEFAULT 0 NOT NULL'
            : 'ALTER TABLE recommendation_run ADD COLUMN distilled BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE recommendation_run SET distilled = 1 WHERE candidate_batches IS NOT NULL');
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
