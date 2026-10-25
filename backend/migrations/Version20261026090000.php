<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.chapters and entry.chapters_url: a podcast episode\'s chapters, and the chapters file '
            . 'still to fetch for them (#1808).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('entry')->hasColumn('chapters'), 'entry.chapters already exists.');

        $isMySql = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $this->addSql(sprintf('ALTER TABLE entry ADD chapters %s DEFAULT NULL', $isMySql ? 'JSON' : 'CLOB'));
        $this->addSql(sprintf('ALTER TABLE entry ADD chapters_url %s DEFAULT NULL', $isMySql ? 'LONGTEXT' : 'CLOB'));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('entry')->hasColumn('chapters'), 'entry.chapters is already gone.');

        $this->addSql('ALTER TABLE entry DROP COLUMN chapters');
        $this->addSql('ALTER TABLE entry DROP COLUMN chapters_url');
    }
}
