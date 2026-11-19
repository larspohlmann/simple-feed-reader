<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119210000 extends AbstractMigration
{
    private const string RETRY_TITLES_WITHOUT_SYNOPSIS
        = 'UPDATE trending_story SET llm_title_member_count = 0 WHERE llm_title IS NOT NULL AND llm_synopsis IS NULL';

    public function getDescription(): string
    {
        return 'A Trending story synopsis holds up to 900 characters (#2384).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('trending_story')->getColumn('llm_synopsis')->getLength() === 900,
            'Trending story synopses already hold 900 characters.',
        );
        $this->addSql(self::RETRY_TITLES_WITHOUT_SYNOPSIS);
        $this->resizeSynopsis(900);
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('trending_story')->getColumn('llm_synopsis')->getLength() === 500,
            'Trending story synopses already hold 500 characters.',
        );
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('UPDATE trending_story SET llm_synopsis = NULL WHERE CHAR_LENGTH(llm_synopsis) > 500');
        }
        $this->resizeSynopsis(500);
    }

    /** MySQL commits each DDL statement, so a half-run migration is not rolled back. */
    public function isTransactional(): bool
    {
        return false;
    }

    /** SQLite swaps the column rather than rebuilding the table, which would cascade-delete every story's rows. */
    private function resizeSynopsis(int $length): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(\sprintf('ALTER TABLE trending_story MODIFY llm_synopsis VARCHAR(%d) DEFAULT NULL', $length));

            return;
        }
        if (!$platform instanceof SQLitePlatform) {
            throw new \RuntimeException('Unsupported database platform for the Trending story synopsis migration.');
        }
        $this->addSql(\sprintf('ALTER TABLE trending_story ADD COLUMN llm_synopsis_resized VARCHAR(%d) DEFAULT NULL', $length));
        $this->addSql('UPDATE trending_story SET llm_synopsis_resized = llm_synopsis');
        $this->addSql('ALTER TABLE trending_story DROP COLUMN llm_synopsis');
        $this->addSql('ALTER TABLE trending_story RENAME COLUMN llm_synopsis_resized TO llm_synopsis');
    }
}
