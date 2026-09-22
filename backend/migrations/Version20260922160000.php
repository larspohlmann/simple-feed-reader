<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persisted saved-search membership (#1116): the saved_search_entry table the
 * sweep fills, and the per-search high-water mark it advances.
 */
final class Version20260922160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add saved_search_entry and saved_search.matched_up_to_entry_id (#1116).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('saved_search_entry'), 'saved_search_entry already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE saved_search_entry (
                    saved_search_id INT NOT NULL,
                    entry_id INT NOT NULL,
                    matched_at DATETIME NOT NULL,
                    INDEX IDX_92B2413F50DC2954 (saved_search_id),
                    INDEX idx_saved_search_entry_entry (entry_id),
                    PRIMARY KEY (saved_search_id, entry_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql('ALTER TABLE saved_search_entry ADD CONSTRAINT FK_92B2413F50DC2954 FOREIGN KEY (saved_search_id) REFERENCES saved_search (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE saved_search_entry ADD CONSTRAINT FK_92B2413FBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE saved_search ADD matched_up_to_entry_id INT DEFAULT 0 NOT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE saved_search_entry (
                    matched_at DATETIME NOT NULL,
                    saved_search_id INTEGER NOT NULL,
                    entry_id INTEGER NOT NULL,
                    PRIMARY KEY (saved_search_id, entry_id),
                    CONSTRAINT FK_92B2413F50DC2954 FOREIGN KEY (saved_search_id) REFERENCES saved_search (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                    CONSTRAINT FK_92B2413FBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
                )
                SQL);
            $this->addSql('CREATE INDEX IDX_92B2413F50DC2954 ON saved_search_entry (saved_search_id)');
            $this->addSql('CREATE INDEX idx_saved_search_entry_entry ON saved_search_entry (entry_id)');
            $this->addSql('ALTER TABLE saved_search ADD COLUMN matched_up_to_entry_id INTEGER DEFAULT 0 NOT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the saved-search membership migration.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE saved_search_entry');
        $this->addSql('ALTER TABLE saved_search DROP COLUMN matched_up_to_entry_id');
    }
}
