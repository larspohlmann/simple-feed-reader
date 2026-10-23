<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023120000 extends AbstractMigration
{
    private const string COLUMN = 'parent_id';

    public function getDescription(): string
    {
        return 'Add tag.parent_id: one level of parent tags (#1736)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('tag')->hasColumn(self::COLUMN), 'tag.parent_id already exists.');

        $this->abortIfUnsupportedPlatform();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE tag ADD parent_id INT DEFAULT NULL');
            $this->addSql(
                'ALTER TABLE tag ADD CONSTRAINT FK_389B783727ACA70 FOREIGN KEY (parent_id) '
                . 'REFERENCES tag (id) ON DELETE SET NULL',
            );
        } else {
            $this->addSql(
                'ALTER TABLE tag ADD COLUMN parent_id INTEGER DEFAULT NULL '
                . 'CONSTRAINT FK_389B783727ACA70 REFERENCES tag (id) ON DELETE SET NULL',
            );
        }
        $this->addSql('CREATE INDEX IDX_389B783727ACA70 ON tag (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('tag')->hasColumn(self::COLUMN), 'tag.parent_id does not exist.');

        $this->abortIfUnsupportedPlatform();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE tag DROP FOREIGN KEY FK_389B783727ACA70');
            $this->addSql('DROP INDEX IDX_389B783727ACA70 ON tag');
            $this->addSql('ALTER TABLE tag DROP COLUMN parent_id');

            return;
        }
        $this->addSql('DROP INDEX IDX_389B783727ACA70');
        $this->addSql('ALTER TABLE tag DROP COLUMN parent_id');
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
