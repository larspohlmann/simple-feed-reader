<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.title_derived (#1495).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry ADD COLUMN title_derived BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP COLUMN title_derived');
    }
}
