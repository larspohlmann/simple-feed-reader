<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\EntrySearchIndex;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030170000 extends AbstractMigration
{
    private const string MINIMUM_SQLITE_VERSION = '3.45.0';

    public function getDescription(): string
    {
        return 'SQLite: the entry_search FTS5 index, so a search ignores case and accents (#1920)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->isSqlite(), 'MySQL compares search terms through its utf8mb4_0900_ai_ci collation.');
        $version = $this->connection->fetchOne('SELECT sqlite_version()');
        $this->abortIf(
            !\is_string($version) || version_compare($version, self::MINIMUM_SQLITE_VERSION, '<'),
            \sprintf('The search index needs SQLite %s or newer for accent-free trigrams.', self::MINIMUM_SQLITE_VERSION),
        );

        foreach (EntrySearchIndex::install() as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->isSqlite(), 'MySQL has no search index.');

        foreach (EntrySearchIndex::uninstall() as $statement) {
            $this->addSql($statement);
        }
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }
}
