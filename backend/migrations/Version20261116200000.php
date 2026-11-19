<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Outbound\Fetch\Support\HostKey;
use App\Service\Outbound\Fetch\Support\LandingSite;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Name resolved short links' cards after the site they land on (#2275).";
    }

    public function up(Schema $schema): void
    {
        /** @var list<array{id: int|string, url: string, site_name: string, resolved_url: string}> $links */
        $links = $this->connection->fetchAllAssociative(
            'SELECT link.id, link.url, link.site_name, landing.resolved_url FROM shared_link link'
            . ' JOIN shared_link_resolution landing ON landing.shared_link_id = link.id'
            . ' WHERE landing.resolved_url IS NOT NULL',
        );
        foreach ($links as $link) {
            $landingSite = LandingSite::pickHost($link['url'], $link['resolved_url']);
            if ($link['site_name'] !== HostKey::forUrl($link['url']) || $link['site_name'] === $landingSite) {
                continue;
            }
            $this->addSql(\sprintf(
                'UPDATE shared_link SET site_name = %s WHERE id = %d',
                $this->connection->quote($landingSite),
                (int) $link['id'],
            ));
        }
    }

    public function down(Schema $schema): void
    {
    }
}
