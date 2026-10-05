<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_ai_settings.suppression_refused_by_model, the model that refused suppressed reasoning (#1388).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_ai_settings')->hasColumn('suppression_refused_by_model'),
            'user_ai_settings.suppression_refused_by_model already exists.',
        );

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_ai_settings ADD suppression_refused_by_model VARCHAR(255) DEFAULT NULL'
            : 'ALTER TABLE user_ai_settings ADD COLUMN suppression_refused_by_model VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('suppression_refused_by_model'),
            'user_ai_settings.suppression_refused_by_model is already gone.',
        );

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_ai_settings DROP suppression_refused_by_model'
            : 'ALTER TABLE user_ai_settings DROP COLUMN suppression_refused_by_model');
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
