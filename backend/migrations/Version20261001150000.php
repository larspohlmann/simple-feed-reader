<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.image_renditions, the declared-width renditions of the lead image (#1330)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('entry')->hasColumn('image_renditions'),
            'entry.image_renditions already exists.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry ADD image_renditions JSON DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE entry ADD COLUMN image_renditions CLOB DEFAULT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the entry image renditions migration.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP COLUMN image_renditions');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
