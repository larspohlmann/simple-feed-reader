<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118130000 extends AbstractMigration
{
    private const array COLUMNS_BY_NAME = [
        'fallback_title' => 'VARCHAR(1024) DEFAULT NULL',
        'fallback_entry_id' => 'INT DEFAULT NULL',
        'llm_title' => 'VARCHAR(255) DEFAULT NULL',
        'llm_title_member_count' => 'INT DEFAULT NULL',
        'llm_title_attempted_at' => 'DATETIME DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Each Trending story stores a fallback title and an LLM title (#2329).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('trending_story')->hasColumn('fallback_title'),
            'Trending stories already carry their titles.',
        );

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE trending_story ' . implode(', ', array_map(
                static fn (string $name, string $definition): string => "ADD $name $definition",
                array_keys(self::COLUMNS_BY_NAME),
                self::COLUMNS_BY_NAME,
            )));

            return;
        }
        $this->requireSqlite($platform);
        foreach (self::COLUMNS_BY_NAME as $name => $definition) {
            $this->addSql(\sprintf('ALTER TABLE trending_story ADD COLUMN %s %s', $name, $definition));
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('trending_story')->hasColumn('fallback_title'),
            'Trending stories already carry no titles.',
        );

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE trending_story ' . implode(', ', array_map(
                static fn (string $name): string => "DROP $name",
                array_keys(self::COLUMNS_BY_NAME),
            )));

            return;
        }
        $this->requireSqlite($platform);
        foreach (array_keys(self::COLUMNS_BY_NAME) as $name) {
            $this->addSql("ALTER TABLE trending_story DROP COLUMN $name");
        }
    }

    /** MySQL commits each DDL statement, and the single ALTER is atomic there. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function requireSqlite(object $platform): void
    {
        if (!$platform instanceof SQLitePlatform) {
            throw new \RuntimeException('Unsupported database platform for the Trending story titles migration.');
        }
    }
}
