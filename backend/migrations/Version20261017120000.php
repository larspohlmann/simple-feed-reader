<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create saved_entry_origin: the source title and icon of an article saved from a left feed (#1666).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('saved_entry_origin'), 'saved_entry_origin already exists.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE saved_entry_origin (entry_id INT NOT NULL, title VARCHAR(512) DEFAULT NULL,'
                . ' favicon_url VARCHAR(2048) DEFAULT NULL, PRIMARY KEY (entry_id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE saved_entry_origin ADD CONSTRAINT fk_saved_entry_origin_entry'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');

            return;
        }

        // INT, not INTEGER: a lone INTEGER primary key is a rowid alias, which Doctrine reads back as auto-increment.
        $this->addSql('CREATE TABLE saved_entry_origin (entry_id INT NOT NULL, title VARCHAR(512) DEFAULT NULL,'
            . ' favicon_url VARCHAR(2048) DEFAULT NULL, PRIMARY KEY (entry_id),'
            . ' CONSTRAINT fk_saved_entry_origin_entry FOREIGN KEY (entry_id) REFERENCES entry (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('saved_entry_origin'), 'saved_entry_origin does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE saved_entry_origin');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
