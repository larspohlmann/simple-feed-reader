<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An untitled entry stores an empty title instead of the "(untitled)" placeholder (#1522).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE entry SET title = '' WHERE title = '(untitled)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE entry SET title = '(untitled)' WHERE title = ''");
    }
}
