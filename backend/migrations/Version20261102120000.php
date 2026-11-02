<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.quoted_link_card: the link card a quoted post shows (#1974)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('entry')->hasColumn('quoted_link_card'),
            'entry.quoted_link_card already exists.',
        );

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry ADD quoted_link_card JSON DEFAULT NULL');

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE entry ADD COLUMN quoted_link_card CLOB DEFAULT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the quoted link card migration.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP COLUMN quoted_link_card');
    }
}
