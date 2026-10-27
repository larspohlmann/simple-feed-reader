<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recommendation_run_log.adjusted_setting and adjusted_value: what a rejected-request fallback '
            . 'changed after the call (#1833).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('recommendation_run_log')->hasColumn('adjusted_setting'),
            'recommendation_run_log.adjusted_setting already exists.',
        );

        $this->addSql('ALTER TABLE recommendation_run_log ADD adjusted_setting VARCHAR(24) DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run_log ADD adjusted_value VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('recommendation_run_log')->hasColumn('adjusted_setting'),
            'recommendation_run_log.adjusted_setting is already gone.',
        );

        $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN adjusted_setting');
        $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN adjusted_value');
    }
}
