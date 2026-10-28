<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\FailedMessageRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\TemporaryMessengerMessagesTable;
use Doctrine\DBAL\Connection;

final class FailedMessageRepositoryTest extends DbTestCase
{
    protected function tearDown(): void
    {
        TemporaryMessengerMessagesTable::drop($this->connection());
        parent::tearDown();
    }

    public function testAStackThatNeverFailedAMessageHasAnEmptyBacklog(): void
    {
        $backlog = $this->repository()->backlog();

        self::assertSame(0, $backlog->waiting);
        self::assertNull($backlog->oldestQueuedAt);
    }

    public function testCountsOnlyTheFailureQueueAndFindsItsOldestMessage(): void
    {
        TemporaryMessengerMessagesTable::create($this->connection());
        $this->insertMessage('failed', '2026-10-28 08:00:00');
        $this->insertMessage('failed', '2026-10-28 07:30:00');
        $this->insertMessage('default', '2026-10-28 06:00:00');

        $backlog = $this->repository()->backlog();

        self::assertSame(2, $backlog->waiting);
        self::assertSame('2026-10-28 07:30:00', $backlog->oldestQueuedAt?->format('Y-m-d H:i:s'));
    }

    private function insertMessage(string $queueName, string $createdAt): void
    {
        $this->connection()->insert('messenger_messages', [
            'body' => '{}',
            'headers' => '{}',
            'queue_name' => $queueName,
            'created_at' => $createdAt,
            'available_at' => $createdAt,
        ]);
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }

    private function repository(): FailedMessageRepository
    {
        $repository = self::getContainer()->get(FailedMessageRepository::class);
        self::assertInstanceOf(FailedMessageRepository::class, $repository);

        return $repository;
    }
}
