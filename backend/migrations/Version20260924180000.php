<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.body_is_opening_post (#1140).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry ADD COLUMN body_is_opening_post BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP COLUMN body_is_opening_post');
    }
}
