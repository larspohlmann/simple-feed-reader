<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.image_url_hash, so an image URL measured before hands its ladder on unprobed (#1855)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('entry')->hasColumn('image_url_hash'),
            'entry.image_url_hash already exists.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry ADD image_url_hash VARCHAR(64) DEFAULT NULL');
            $this->addSql('UPDATE entry SET image_url_hash = SHA2(image_url, 256) WHERE image_url IS NOT NULL');
            $this->addSql('CREATE INDEX idx_entry_image_url_hash ON entry (image_url_hash)');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE entry ADD COLUMN image_url_hash VARCHAR(64) DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_entry_image_url_hash ON entry (image_url_hash)');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the entry image URL hash migration.');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('DROP INDEX idx_entry_image_url_hash ON entry');
        } else {
            $this->addSql('DROP INDEX idx_entry_image_url_hash');
        }
        $this->addSql('ALTER TABLE entry DROP COLUMN image_url_hash');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
