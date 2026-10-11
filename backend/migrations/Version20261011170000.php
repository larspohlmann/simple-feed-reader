<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index share_enrichment for the claimable link-card query (#1514).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('share_enrichment')->hasIndex('idx_share_enrichment_claimable'),
            'idx_share_enrichment_claimable already exists.',
        );

        $this->addSql('CREATE INDEX idx_share_enrichment_claimable ON share_enrichment (kind, done_at, queued_at)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('share_enrichment')->hasIndex('idx_share_enrichment_claimable'),
            'idx_share_enrichment_claimable does not exist.',
        );

        $this->addSql(
            $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'DROP INDEX idx_share_enrichment_claimable ON share_enrichment'
                : 'DROP INDEX idx_share_enrichment_claimable',
        );
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
