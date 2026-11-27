<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'for_you_stream_entry holds the entries the for you stream matched per account (#2582)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('for_you_stream_entry'), 'for_you_stream_entry already exists.');

        if ($this->mysql()) {
            $this->upMySql();

            return;
        }

        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('for_you_stream_entry'), 'for_you_stream_entry does not exist.');

        if ($this->mysql()) {
            $this->addSql('ALTER TABLE for_you_stream_entry DROP FOREIGN KEY FK_87C97081A76ED395');
            $this->addSql('ALTER TABLE for_you_stream_entry DROP FOREIGN KEY FK_87C97081BA364942');
        }
        $this->addSql('DROP TABLE for_you_stream_entry');
    }

    /** MySQL commits each DDL statement, so a wrapping transaction protects nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql('CREATE TABLE for_you_stream_entry (matched_at DATETIME NOT NULL,'
            . ' score DOUBLE PRECISION NOT NULL, matched_stems VARCHAR(255) NOT NULL,'
            . ' user_id INT NOT NULL, entry_id INT NOT NULL, INDEX IDX_87C97081A76ED395 (user_id),'
            . ' INDEX idx_for_you_stream_entry_entry (entry_id), PRIMARY KEY (user_id, entry_id))'
            . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE for_you_stream_entry ADD CONSTRAINT FK_87C97081A76ED395 FOREIGN KEY (user_id)'
            . ' REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE for_you_stream_entry ADD CONSTRAINT FK_87C97081BA364942 FOREIGN KEY (entry_id)'
            . ' REFERENCES entry (id) ON DELETE CASCADE');
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE for_you_stream_entry (matched_at DATETIME NOT NULL,'
            . ' score DOUBLE PRECISION NOT NULL, matched_stems VARCHAR(255) NOT NULL,'
            . ' user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, PRIMARY KEY (user_id, entry_id),'
            . ' CONSTRAINT FK_87C97081A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_87C97081BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_87C97081A76ED395 ON for_you_stream_entry (user_id)');
        $this->addSql('CREATE INDEX idx_for_you_stream_entry_entry ON for_you_stream_entry (entry_id)');
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
