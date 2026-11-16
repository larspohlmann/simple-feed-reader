<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Index;
use Doctrine\Migrations\Exception\SkipMigration;
use DoctrineMigrations\Version20261117120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Version20261117120000Test extends TestCase
{
    private const array TABLES = ['social_notification', 'social_notification_poll'];

    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 2) . '/migrations/Version20261117120000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->executeStatement('CREATE TABLE social_account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
    }

    public function testUpCreatesBothTablesAndDownDropsThem(): void
    {
        $this->migrate('up');

        self::assertTrue($this->connection->createSchemaManager()->tablesExist(self::TABLES));
        $indexNames = array_map(
            static fn (Index $index): string => $index->getObjectName()->toString(),
            $this->connection->createSchemaManager()->listTableIndexes('social_notification'),
        );
        self::assertContains('uniq_social_notification_account_remote', $indexNames);
        self::assertContains('idx_social_notification_group', $indexNames);

        $this->migrate('down');

        foreach (self::TABLES as $table) {
            self::assertFalse($this->connection->createSchemaManager()->tablesExist([$table]));
        }
    }

    public function testDeletingAnAccountTakesItsNotificationsAndPollWithIt(): void
    {
        $this->migrate('up');
        $this->connection->executeStatement('INSERT INTO social_account (id) VALUES (1)');
        $this->connection->executeStatement(
            'INSERT INTO social_notification (remote_id, kind, created_at, seen, actor_handle, actor_profile_url,'
            . " subject_group_key, subject_link_url, social_account_id) VALUES ('r1', 'like',"
            . " '2026-11-17 10:00:00', 0, 'a', 'https://a', 'k', 'https://l', 1)",
        );
        $this->connection->executeStatement('INSERT INTO social_notification_poll (social_account_id) VALUES (1)');

        $this->connection->executeStatement('DELETE FROM social_account WHERE id = 1');

        self::assertSame(0, $this->rowCount('social_notification'));
        self::assertSame(0, $this->rowCount('social_notification_poll'));
    }

    public function testASecondUpIsSkipped(): void
    {
        $this->migrate('up');

        $migration = new Version20261117120000($this->connection, new NullLogger());
        $this->expectException(SkipMigration::class);
        $migration->up($this->connection->createSchemaManager()->introspectSchema());
    }

    private function rowCount(string $table): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261117120000($this->connection, new NullLogger());
        $schema = $this->connection->createSchemaManager()->introspectSchema();
        'up' === $direction ? $migration->up($schema) : $migration->down($schema);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
