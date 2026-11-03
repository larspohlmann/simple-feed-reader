<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104090000 extends AbstractMigration
{
    private const string TABLE = 'entry_share';

    public function getDescription(): string
    {
        return 'Records when the reader shared an entry: entry_share (#2007).';
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
        $this->addSql('CREATE TABLE entry_share (shared_at DATETIME NOT NULL, user_id INT NOT NULL,'
            . ' entry_id INT NOT NULL, INDEX IDX_6B1CFEFCA76ED395 (user_id), INDEX IDX_6B1CFEFCBA364942 (entry_id),'
            . ' INDEX idx_entry_share_user_shared_at (user_id, shared_at), PRIMARY KEY (user_id, entry_id))'
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entry_share ADD CONSTRAINT FK_6B1CFEFCA76ED395'
            . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entry_share ADD CONSTRAINT FK_6B1CFEFCBA364942'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
    }

    private function upOnSqlite(): void
    {
        $this->addSql('CREATE TABLE entry_share (shared_at DATETIME NOT NULL, user_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, PRIMARY KEY (user_id, entry_id),'
            . ' CONSTRAINT FK_6B1CFEFCA76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_6B1CFEFCBA364942 FOREIGN KEY (entry_id)'
            . ' REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_6B1CFEFCA76ED395 ON entry_share (user_id)');
        $this->addSql('CREATE INDEX IDX_6B1CFEFCBA364942 ON entry_share (entry_id)');
        $this->addSql('CREATE INDEX idx_entry_share_user_shared_at ON entry_share (user_id, shared_at)');
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
