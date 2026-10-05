<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the engine kind and scoring protocol of a connection\'s model, and a run\'s scoring protocol (#1395).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_ai_settings')->hasColumn('model_kind'),
            'user_ai_settings.model_kind already exists.',
        );

        $add = $this->mysql() ? 'ADD' : 'ADD COLUMN';
        $this->addSql(\sprintf('ALTER TABLE user_ai_settings %s model_kind VARCHAR(16) DEFAULT NULL', $add));
        $this->addSql(\sprintf('ALTER TABLE user_ai_settings %s scoring_protocol VARCHAR(16) DEFAULT NULL', $add));
        $this->addSql(\sprintf('ALTER TABLE recommendation_run %s scoring_protocol VARCHAR(16) DEFAULT NULL', $add));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('model_kind'),
            'user_ai_settings.model_kind is already gone.',
        );

        $drop = $this->mysql() ? 'DROP' : 'DROP COLUMN';
        $this->addSql(\sprintf('ALTER TABLE user_ai_settings %s model_kind', $drop));
        $this->addSql(\sprintf('ALTER TABLE user_ai_settings %s scoring_protocol', $drop));
        $this->addSql(\sprintf('ALTER TABLE recommendation_run %s scoring_protocol', $drop));
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
