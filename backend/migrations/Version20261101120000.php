<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101120000 extends AbstractMigration
{
    private const string POST_URI = '#^at://([^/\s]+)/app\.bsky\.feed\.post/([^/\s]+)\z#';

    public function getDescription(): string
    {
        return 'Rehash Bluesky entries from their AT URI guid, so timeline and RSS copies collapse (#1954)';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative("SELECT id, guid FROM entry WHERE guid LIKE 'at://%'");
        foreach ($rows as $row) {
            if (preg_match(self::POST_URI, (string) $row['guid'], $parts) !== 1) {
                continue;
            }
            $this->addSql(
                'UPDATE entry SET url_hash = ? WHERE id = ?',
                [hash('sha256', 'https://bsky.app/profile/' . $parts[1] . '/post/' . $parts[2]), $row['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The handle-form hashes are not kept.');
    }
}
