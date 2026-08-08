<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Worker\Handler\PurgeFailedMessagesHandler;
use App\Service\Worker\Message\PurgeFailedMessages;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * `messenger_messages` is created lazily by `auto_setup` on the first failed
 * delivery (config/packages/doctrine.yaml's `schema_filter` hides it from
 * the ORM-built test schema), so a stack that never failed a message has no
 * table for this handler to purge against. Both cases are exercised here:
 * the table missing, and the table present with rows on both sides of the
 * retention boundary.
 */
final class PurgeFailedMessagesHandlerTest extends DbTestCase
{
    public function testFiringWithoutTheFailedTransportTableDoesNotThrow(): void
    {
        $this->handler()->__invoke(new PurgeFailedMessages());

        $this->addToAssertionCount(1);
    }

    public function testFiringDeletesOnlyOldRowsFromTheFailedQueue(): void
    {
        $this->createMessengerMessagesTable();
        $this->insertMessage(id: 1, queueName: 'failed', createdAt: '-31 days');
        $this->insertMessage(id: 2, queueName: 'failed', createdAt: '-1 day');
        $this->insertMessage(id: 3, queueName: 'default', createdAt: '-31 days');

        $this->handler()->__invoke(new PurgeFailedMessages());

        self::assertSame([2, 3], $this->remainingMessageIds());
    }

    /**
     * Built through the DBAL schema API rather than raw DDL, so the column
     * types land correctly on both SQLite (native test run) and MySQL
     * (Docker leg) — `AUTOINCREMENT`/`CLOB` are SQLite-only keywords that a
     * MySQL server rejects outright. Created as a TEMPORARY table: plain
     * `CREATE TABLE` implicitly commits on MySQL, which would tear down the
     * transaction DAMA's test bundle wraps every test in and cascade
     * failures into every test that runs afterwards. `CREATE TEMPORARY
     * TABLE` is the documented exception to that implicit commit.
     */
    private function createMessengerMessagesTable(): void
    {
        $table = new Table('messenger_messages');
        $table->addColumn('id', Types::INTEGER)->setAutoincrement(true);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);
        $table->addColumn('queue_name', Types::STRING, ['length' => 190]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE);
        $table->addColumn('available_at', Types::DATETIME_MUTABLE);
        $table->addColumn('delivered_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        $platform = $this->connection()->getDatabasePlatform();

        foreach ($platform->getCreateTableSQL($table) as $statement) {
            $temporaryStatement = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $statement);

            $this->connection()->executeStatement($temporaryStatement);
        }
    }

    private function insertMessage(int $id, string $queueName, string $createdAt): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO messenger_messages '
            . '(id, body, headers, queue_name, created_at, available_at) '
            . 'VALUES (:id, :body, :headers, :queue, :createdAt, :availableAt)',
            [
                'id' => $id,
                'body' => '{}',
                'headers' => '{}',
                'queue' => $queueName,
                'createdAt' => (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
                'availableAt' => (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function remainingMessageIds(): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->connection()->fetchAllAssociative('SELECT id FROM messenger_messages ORDER BY id');

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }

    private function handler(): PurgeFailedMessagesHandler
    {
        /** @var PurgeFailedMessagesHandler $handler */
        $handler = self::getContainer()->get(PurgeFailedMessagesHandler::class);

        return $handler;
    }
}
