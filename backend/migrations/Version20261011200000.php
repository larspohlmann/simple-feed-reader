<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop entry.share_url: a titled share targets the entry\'s own url (#1547).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('entry')->hasColumn('share_url'), 'entry.share_url is already gone.');

        $this->addSql('ALTER TABLE entry DROP COLUMN share_url');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf($schema->getTable('entry')->hasColumn('share_url'), 'entry.share_url already exists.');

        $this->addSql('ALTER TABLE entry ADD share_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('UPDATE entry SET share_url = url WHERE share_kind IS NOT NULL AND title_derived = 0');
    }
}
