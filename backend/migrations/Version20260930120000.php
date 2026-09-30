<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reddit link posts drop their article url; drop entry.body_is_opening_post (#1291).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE entry SET url = NULL, url_hash = NULL WHERE body_is_opening_post = 1');
        $this->addSql('ALTER TABLE entry DROP COLUMN body_is_opening_post');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry ADD COLUMN body_is_opening_post BOOLEAN DEFAULT 0 NOT NULL');
    }
}
