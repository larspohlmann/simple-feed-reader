<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A text column, as entry's VARCHAR(2048) columns already fill MySQL's 65,535-byte row; a plain ADD COLUMN, so SQLite
 * keeps the entry table and its entry_search triggers.
 */
final class Version20261123120000 extends AbstractMigration
{
    private const string COLUMN = 'discussion_home_url';

    public function getDescription(): string
    {
        return 'Store a Lemmy account post\'s address on the reader\'s own instance (#2496).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('entry')->hasColumn(self::COLUMN), self::COLUMN . ' exists.');
        $text = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'LONGTEXT' : 'CLOB';
        $this->addSql('ALTER TABLE entry ADD ' . self::COLUMN . ' ' . $text . ' DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('entry')->hasColumn(self::COLUMN), self::COLUMN . ' does not exist.');
        $this->addSql('ALTER TABLE entry DROP COLUMN ' . self::COLUMN);
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
