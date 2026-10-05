<?php

declare(strict_types=1);

namespace DoctrineMigrations;

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

        $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN suppression_refused_by_model VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('suppression_refused_by_model'),
            'user_ai_settings.suppression_refused_by_model is already gone.',
        );

        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN suppression_refused_by_model');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
