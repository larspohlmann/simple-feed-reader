<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014120000 extends AbstractMigration
{
    private const array STRING_COLUMNS = [
        'quoted_author_name' => 255,
        'quoted_author_handle' => 255,
        'quoted_nested_handle' => 255,
    ];

    private const array URL_COLUMNS = ['quoted_url', 'quoted_author_avatar_url', 'quoted_nested_url'];

    public function getDescription(): string
    {
        return 'Add entry.quoted_*: the post a quote share quotes, shown nested (#1612).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('entry')->hasColumn('quoted_url'), 'entry.quoted_url already exists.');

        $this->abortIfUnsupportedPlatform();
        $isMySql = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        foreach (self::STRING_COLUMNS as $column => $length) {
            $this->addSql(sprintf('ALTER TABLE entry ADD %s VARCHAR(%d) DEFAULT NULL', $column, $length));
        }
        foreach (self::URL_COLUMNS as $column) {
            $this->addSql(sprintf('ALTER TABLE entry ADD %s %s DEFAULT NULL', $column, $isMySql ? 'LONGTEXT' : 'CLOB'));
        }
        $this->addSql('ALTER TABLE entry ADD quoted_posted_at DATETIME DEFAULT NULL');
        $this->addSql(sprintf('ALTER TABLE entry ADD quoted_text %s DEFAULT NULL', $isMySql ? 'LONGTEXT' : 'CLOB'));
        $this->addSql(sprintf('ALTER TABLE entry ADD quoted_media %s DEFAULT NULL', $isMySql ? 'JSON' : 'CLOB'));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('entry')->hasColumn('quoted_url'), 'entry.quoted_url is already gone.');

        $this->abortIfUnsupportedPlatform();
        foreach ([...array_keys(self::STRING_COLUMNS), ...self::URL_COLUMNS, 'quoted_posted_at', 'quoted_text', 'quoted_media'] as $column) {
            $this->addSql(sprintf('ALTER TABLE entry DROP COLUMN %s', $column));
        }
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
