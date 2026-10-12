<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.author_url and entry.author_avatar_url: the profile and picture a private feed\'s post shows its author with (#1599).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('entry')->hasColumn('author_url'), 'entry.author_url already exists.');

        $this->addSql('ALTER TABLE entry ADD author_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE entry ADD author_avatar_url VARCHAR(2048) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('entry')->hasColumn('author_url'), 'entry.author_url is already gone.');

        $this->addSql('ALTER TABLE entry DROP COLUMN author_url');
        $this->addSql('ALTER TABLE entry DROP COLUMN author_avatar_url');
    }
}
