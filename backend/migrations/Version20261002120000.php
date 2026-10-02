<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recommendation_run.engine_kind, the engine a run was packed for (#1345)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('recommendation_run')->hasColumn('engine_kind'),
            'recommendation_run.engine_kind already exists.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run ADD engine_kind VARCHAR(16) DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE recommendation_run ADD COLUMN engine_kind VARCHAR(16) DEFAULT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the recommendation run engine kind migration.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recommendation_run DROP COLUMN engine_kind');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
