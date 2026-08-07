<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Worker\Handler\PurgeFailedMessagesHandler;
use App\Service\Worker\Message\PurgeFailedMessages;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Connection;

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

    private function createMessengerMessagesTable(): void
    {
        $this->connection()->executeStatement(
            'CREATE TABLE messenger_messages ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'body CLOB NOT NULL, '
            . 'headers CLOB NOT NULL, '
            . 'queue_name VARCHAR(190) NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'available_at DATETIME NOT NULL, '
            . 'delivered_at DATETIME DEFAULT NULL'
            . ')',
        );
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
