<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121150000 extends AbstractMigration
{
    private const string COLUMN = 'like_count';

    public function getDescription(): string
    {
        return 'Store a social post\'s like count on its canonical item (#2362).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('canonical_item')->hasColumn(self::COLUMN), self::COLUMN . ' exists.');
        $this->addSql('ALTER TABLE canonical_item ADD ' . self::COLUMN . ' INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('canonical_item')->hasColumn(self::COLUMN), self::COLUMN . ' does not exist.');
        $this->addSql('ALTER TABLE canonical_item DROP COLUMN ' . self::COLUMN);
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
