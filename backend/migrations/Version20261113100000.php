<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113100000 extends AbstractMigration
{
    private const string TABLE = 'network_exclusion';
    private const string FOR_YOU_TABLE = 'for_you_network_exclusion';

    public function getDescription(): string
    {
        return 'One network_exclusion table for For you and All items, replacing for_you_network_exclusion (#2202).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();
        if ($this->isMySql()) {
            $this->createNetworkExclusionOnMySql();
        } else {
            $this->createNetworkExclusionOnSqlite();
        }
        $this->addSql('INSERT INTO network_exclusion (user_id, network, surface)'
            . " SELECT user_id, network, 'for_you' FROM for_you_network_exclusion");
        $this->addSql('DROP TABLE ' . self::FOR_YOU_TABLE);
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        if ($this->isMySql()) {
            $this->createForYouExclusionOnMySql();
        } else {
            $this->createForYouExclusionOnSqlite();
        }
        $this->addSql('INSERT INTO for_you_network_exclusion (user_id, network)'
            . " SELECT user_id, network FROM network_exclusion WHERE surface = 'for_you'");
        $this->addSql('DROP TABLE ' . self::TABLE);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function createNetworkExclusionOnMySql(): void
    {
        $this->addSql('CREATE TABLE network_exclusion (id INT AUTO_INCREMENT NOT NULL,'
            . ' network VARCHAR(16) NOT NULL, surface VARCHAR(16) NOT NULL, user_id INT NOT NULL,'
            . ' INDEX idx_network_exclusion_user (user_id),'
            . ' UNIQUE INDEX uniq_network_exclusion (user_id, network, surface), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE network_exclusion ADD CONSTRAINT fk_network_exclusion_user'
            . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    private function createNetworkExclusionOnSqlite(): void
    {
        $this->addSql('CREATE TABLE network_exclusion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' network VARCHAR(16) NOT NULL, surface VARCHAR(16) NOT NULL, user_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_network_exclusion_user FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_network_exclusion_user ON network_exclusion (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_network_exclusion ON network_exclusion (user_id, network, surface)');
    }

    private function createForYouExclusionOnMySql(): void
    {
        $this->addSql('CREATE TABLE for_you_network_exclusion (id INT AUTO_INCREMENT NOT NULL,'
            . ' network VARCHAR(16) NOT NULL, user_id INT NOT NULL,'
            . ' INDEX idx_for_you_network_exclusion_user (user_id),'
            . ' UNIQUE INDEX uniq_for_you_network_exclusion (user_id, network), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE for_you_network_exclusion ADD CONSTRAINT fk_for_you_network_exclusion_user'
            . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    private function createForYouExclusionOnSqlite(): void
    {
        $this->addSql('CREATE TABLE for_you_network_exclusion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' network VARCHAR(16) NOT NULL, user_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_for_you_network_exclusion_user FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_for_you_network_exclusion_user ON for_you_network_exclusion (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_for_you_network_exclusion'
            . ' ON for_you_network_exclusion (user_id, network)');
    }

    private function isMySql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
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
