<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105100000 extends AbstractMigration
{
    private const string TABLE = 'trending_hidden_entry';

    public function getDescription(): string
    {
        return 'The Trending stories a reader hid: trending_hidden_entry (#2017).';
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
        $this->addSql('CREATE TABLE trending_hidden_entry (hidden_at DATETIME NOT NULL, user_id INT NOT NULL,'
            . ' entry_id INT NOT NULL, INDEX IDX_D6B1BD7CA76ED395 (user_id), INDEX IDX_D6B1BD7CBA364942 (entry_id),'
            . ' PRIMARY KEY (user_id, entry_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE trending_hidden_entry ADD CONSTRAINT FK_D6B1BD7CA76ED395'
            . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trending_hidden_entry ADD CONSTRAINT FK_D6B1BD7CBA364942'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
    }

    private function upOnSqlite(): void
    {
        $this->addSql('CREATE TABLE trending_hidden_entry (hidden_at DATETIME NOT NULL, user_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, PRIMARY KEY (user_id, entry_id),'
            . ' CONSTRAINT FK_D6B1BD7CA76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_D6B1BD7CBA364942 FOREIGN KEY (entry_id)'
            . ' REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D6B1BD7CA76ED395 ON trending_hidden_entry (user_id)');
        $this->addSql('CREATE INDEX IDX_D6B1BD7CBA364942 ON trending_hidden_entry (entry_id)');
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
