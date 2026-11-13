<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113130000 extends AbstractMigration
{
    private const array SURFACE_SLUGS = ['all_items' => 'all-items', 'for_you' => 'for-you'];

    public function getDescription(): string
    {
        return 'Store a network exclusion\'s surface as its URL slug (#2202).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SURFACE_SLUGS as $stored => $slug) {
            $this->renameSurface($stored, $slug);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::SURFACE_SLUGS as $stored => $slug) {
            $this->renameSurface($slug, $stored);
        }
    }

    private function renameSurface(string $from, string $to): void
    {
        $this->addSql(\sprintf("UPDATE network_exclusion SET surface = '%s' WHERE surface = '%s'", $to, $from));
    }
}
