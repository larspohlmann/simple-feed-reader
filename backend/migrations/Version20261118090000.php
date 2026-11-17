<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lemmy community and user feeds resolve their favicon again, as their instance icon (#2320).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE feed SET favicon_url = NULL WHERE url LIKE 'https://%/feeds/c/%' OR url LIKE 'https://%/feeds/u/%'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The cleared icons are not kept; a refresh sets them again.');
    }
}
