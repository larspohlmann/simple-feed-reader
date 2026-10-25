<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000 extends AbstractMigration
{
    private const string TABLE = 'subscription_day';

    public function getDescription(): string
    {
        return 'Create subscription_day: per-subscription daily new and read counts (#1783)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'subscription_day already exists.');

        $this->abortIfUnsupportedPlatform();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql(
                'CREATE TABLE subscription_day (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, '
                . 'arrived INT NOT NULL, read_count INT NOT NULL, subscription_id INT NOT NULL, '
                . 'INDEX IDX_18BC77B9A1887DC (subscription_id), '
                . 'UNIQUE INDEX uniq_subscription_day (subscription_id, day), PRIMARY KEY (id)) '
                . 'DEFAULT CHARACTER SET utf8mb4',
            );
            $this->addSql(
                'ALTER TABLE subscription_day ADD CONSTRAINT FK_18BC77B9A1887DC FOREIGN KEY (subscription_id) '
                . 'REFERENCES subscription (id) ON DELETE CASCADE',
            );

            return;
        }
        $this->addSql(
            'CREATE TABLE subscription_day (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day DATE NOT NULL, '
            . 'arrived INTEGER NOT NULL, read_count INTEGER NOT NULL, subscription_id INTEGER NOT NULL, '
            . 'CONSTRAINT FK_18BC77B9A1887DC FOREIGN KEY (subscription_id) '
            . 'REFERENCES subscription (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
        );
        $this->addSql('CREATE INDEX IDX_18BC77B9A1887DC ON subscription_day (subscription_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscription_day ON subscription_day (subscription_id, day)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'subscription_day does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE subscription_day');
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
