<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The per-user auto-generate interval (#333):
 * user_recommendation_settings.auto_generate_interval_hours. null means
 * "only manually".
 */
final class Version20260809104155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Auto-generate interval (#333): user_recommendation_settings.auto_generate_interval_hours.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_recommendation_settings ADD auto_generate_interval_hours INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_recommendation_settings DROP auto_generate_interval_hours');
    }
}
