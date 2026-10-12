<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed.image_checked_at: when a profile feed\'s picture was last asked of its network (#1599).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('feed')->hasColumn('image_checked_at'), 'feed.image_checked_at already exists.');

        $this->addSql('ALTER TABLE feed ADD image_checked_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('feed')->hasColumn('image_checked_at'), 'feed.image_checked_at is already gone.');

        $this->addSql('ALTER TABLE feed DROP COLUMN image_checked_at');
    }
}
