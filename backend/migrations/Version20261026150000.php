<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_ai_settings.model_max_output_tokens and token_limit_parameter: what the chosen model caps '
            . 'its output at and which field bounds it (#1823).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_ai_settings')->hasColumn('token_limit_parameter'),
            'user_ai_settings.token_limit_parameter already exists.',
        );

        $this->addSql('ALTER TABLE user_ai_settings ADD model_max_output_tokens INT DEFAULT NULL');
        $this->addSql("ALTER TABLE user_ai_settings ADD token_limit_parameter VARCHAR(32) DEFAULT 'max_tokens' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('token_limit_parameter'),
            'user_ai_settings.token_limit_parameter is already gone.',
        );

        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN model_max_output_tokens');
        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN token_limit_parameter');
    }
}
