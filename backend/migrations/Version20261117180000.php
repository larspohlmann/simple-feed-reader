<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feeds holding the bare /favicon.ico fallback resolve their favicon again on the next refresh (#2298).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE feed SET favicon_url = NULL WHERE favicon_url LIKE 'https://%/favicon.ico'"
            . " AND favicon_url NOT LIKE 'https://%/%/favicon.ico'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The cleared fallbacks are not kept; a refresh sets them again.');
    }
}
