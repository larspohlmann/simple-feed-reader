<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Each Trending story stores an LLM synopsis beside its title (#2353).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('trending_story')->hasColumn('llm_synopsis'),
            'Trending stories already carry their synopsis.',
        );
        $this->requireSupported($this->connection->getDatabasePlatform());

        $this->addSql('ALTER TABLE trending_story ADD llm_synopsis VARCHAR(500) DEFAULT NULL');
        $this->addSql('UPDATE trending_story SET llm_title_member_count = 0 WHERE llm_title IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('trending_story')->hasColumn('llm_synopsis'),
            'Trending stories already carry no synopsis.',
        );
        $this->requireSupported($this->connection->getDatabasePlatform());

        $this->addSql('ALTER TABLE trending_story DROP COLUMN llm_synopsis');
    }

    /** MySQL commits each DDL statement, so a half-run migration is not rolled back. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function requireSupported(object $platform): void
    {
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform) {
            throw new \RuntimeException('Unsupported database platform for the Trending story synopsis migration.');
        }
    }
}
