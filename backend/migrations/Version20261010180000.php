<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending_post_enrichment, the Bluesky posts whose embeds await the AppView (#1499).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('pending_post_enrichment'), 'pending_post_enrichment already exists.');

        if ($this->mysql()) {
            $this->addSql('CREATE TABLE pending_post_enrichment (id INT AUTO_INCREMENT NOT NULL,'
                . ' queued_at DATETIME NOT NULL, entry_id INT NOT NULL, UNIQUE INDEX UNIQ_BA362B72BA364942 (entry_id),'
                . ' PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE pending_post_enrichment ADD CONSTRAINT FK_BA362B72BA364942'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');

            return;
        }

        $this->addSql('CREATE TABLE pending_post_enrichment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' queued_at DATETIME NOT NULL, entry_id INTEGER NOT NULL, CONSTRAINT FK_BA362B72BA364942'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BA362B72BA364942 ON pending_post_enrichment (entry_id)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('pending_post_enrichment'), 'pending_post_enrichment does not exist.');

        $this->addSql('DROP TABLE pending_post_enrichment');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
