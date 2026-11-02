<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102130000 extends AbstractMigration
{
    private const string TABLE = 'for_you_network_exclusion';

    public function getDescription(): string
    {
        return 'Keep social networks out of For you: for_you_network_exclusion (#1995).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->upOnMySql();

            return;
        }

        $this->upOnSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE ' . self::TABLE);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upOnMySql(): void
    {
        $this->addSql('CREATE TABLE for_you_network_exclusion (id INT AUTO_INCREMENT NOT NULL,'
            . ' network VARCHAR(16) NOT NULL, user_id INT NOT NULL,'
            . ' INDEX idx_for_you_network_exclusion_user (user_id),'
            . ' UNIQUE INDEX uniq_for_you_network_exclusion (user_id, network), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE for_you_network_exclusion ADD CONSTRAINT fk_for_you_network_exclusion_user'
            . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    private function upOnSqlite(): void
    {
        $this->addSql('CREATE TABLE for_you_network_exclusion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' network VARCHAR(16) NOT NULL, user_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_for_you_network_exclusion_user FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_for_you_network_exclusion_user ON for_you_network_exclusion (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_for_you_network_exclusion'
            . ' ON for_you_network_exclusion (user_id, network)');
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
