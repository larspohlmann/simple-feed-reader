<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The mark a prune sets on a shared link no card or quote uses, so the next prunes delete it only after a grace. */
final class Version20261115150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shared_link.unreferenced_since for mark-then-delete garbage collection (#2239)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('shared_link')->hasColumn('unreferenced_since'),
            'shared_link.unreferenced_since already exists.',
        );
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(
                'ALTER TABLE shared_link ADD unreferenced_since DATETIME DEFAULT NULL,'
                . ' ADD INDEX idx_shared_link_unreferenced_since (unreferenced_since)',
            );

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE shared_link ADD COLUMN unreferenced_since DATETIME DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_shared_link_unreferenced_since ON shared_link (unreferenced_since)');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the shared_link mark migration.');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE shared_link DROP INDEX idx_shared_link_unreferenced_since, DROP unreferenced_since');

            return;
        }
        $this->addSql('DROP INDEX idx_shared_link_unreferenced_since');
        $this->addSql('ALTER TABLE shared_link DROP COLUMN unreferenced_since');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
