<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "A Bluesky link post's card thumbnail no longer doubles as the post's own image (#2477).";
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            'Supports MySQL and SQLite only.',
        );
        $onlyTheLeadImage = $platform instanceof AbstractMySQLPlatform
            ? "JSON_LENGTH(media) = 1 AND JSON_UNQUOTE(JSON_EXTRACT(media, '$[0].url')) = image_url"
            : "json_array_length(media) = 1 AND json_extract(media, '$[0].url') = image_url";

        $this->addSql(
            "UPDATE entry SET media = NULL WHERE guid LIKE 'at://%' AND share_kind = 'link' AND " . $onlyTheLeadImage
            . ' AND EXISTS (SELECT 1 FROM link_card WHERE link_card.entry_id = entry.id'
            . ' AND link_card.thread_post_id IS NULL)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The cleared thumbnails stay on the link card and as the lead image.');
    }
}
