<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011160000 extends AbstractMigration
{
    private const string CARDED_POST = 'title_derived = 1 AND ('
        . 'EXISTS (SELECT 1 FROM link_card WHERE link_card.entry_id = entry.id)'
        . ' OR EXISTS (SELECT 1 FROM share_enrichment'
        . ' WHERE share_enrichment.entry_id = entry.id AND share_enrichment.done_at IS NULL))';
    private const string MEDIA_ENTRY = "url LIKE 'https://www.youtube.com/shorts/%'"
        . " OR url LIKE 'https://youtube.com/shorts/%'"
        . ' OR (title_derived = 1 AND media IS NOT NULL)';

    public function getDescription(): string
    {
        return 'Data only: an entry that showed a link or media card before entries had a share keeps it (#1524).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE entry SET share_kind = 'link' WHERE share_kind IS NULL AND " . self::CARDED_POST);
        $this->addSql("UPDATE entry SET share_kind = 'media' WHERE share_kind IS NULL AND (" . self::MEDIA_ENTRY . ')');
    }

    public function down(Schema $schema): void
    {
        $this->write('The backfilled shares stay: Version20261011150000 drops their columns.');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
