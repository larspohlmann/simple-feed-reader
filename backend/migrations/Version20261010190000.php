<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add link_card, the cards for the links a post shares (#1502).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('link_card'), 'link_card already exists.');

        $this->abortIfUnsupportedPlatform();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE link_card (id INT AUTO_INCREMENT NOT NULL, position SMALLINT NOT NULL,'
                . ' url VARCHAR(2048) NOT NULL, title VARCHAR(500) NOT NULL, description LONGTEXT DEFAULT NULL,'
                . ' image_url VARCHAR(2048) DEFAULT NULL, site_name VARCHAR(255) NOT NULL, entry_id INT NOT NULL,'
                . ' INDEX IDX_A7530A7ABA364942 (entry_id), UNIQUE INDEX uniq_link_card_entry_position'
                . ' (entry_id, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE link_card ADD CONSTRAINT FK_A7530A7ABA364942'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');

            return;
        }

        $this->addSql('CREATE TABLE link_card (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' position SMALLINT NOT NULL, url VARCHAR(2048) NOT NULL, title VARCHAR(500) NOT NULL,'
            . ' description CLOB DEFAULT NULL, image_url VARCHAR(2048) DEFAULT NULL,'
            . ' site_name VARCHAR(255) NOT NULL, entry_id INTEGER NOT NULL, CONSTRAINT FK_A7530A7ABA364942'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_A7530A7ABA364942 ON link_card (entry_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_link_card_entry_position ON link_card (entry_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('link_card'), 'link_card does not exist.');

        $this->addSql('DROP TABLE link_card');
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
