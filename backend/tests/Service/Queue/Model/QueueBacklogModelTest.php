<?php

declare(strict_types=1);

namespace App\Tests\Service\Queue\Model;

use App\Service\Queue\Model\QueueBacklogModel;
use App\Service\Queue\Model\QueueName;
use PHPUnit\Framework\TestCase;

final class QueueBacklogModelTest extends TestCase
{
    public function testAnAggregateRowReadsItsNaiveTimestampAsUtc(): void
    {
        $backlog = QueueBacklogModel::fromAggregate('3', '2026-10-28 09:15:00');

        self::assertSame(3, $backlog->waiting);
        self::assertSame('2026-10-28T09:15:00+00:00', $backlog->oldestQueuedAt?->format(\DATE_ATOM));
    }

    public function testAnEmptyQueueHasNoOldestTime(): void
    {
        $backlog = QueueBacklogModel::fromAggregate(0, null);

        self::assertSame(0, $backlog->waiting);
        self::assertNull($backlog->oldestQueuedAt);
    }

    public function testNoneIsAnEmptyQueue(): void
    {
        self::assertEquals(QueueBacklogModel::fromAggregate(0, null), QueueBacklogModel::none());
    }

    public function testNamingKeepsTheBacklog(): void
    {
        $backlog = QueueBacklogModel::fromAggregate(2, '2026-10-28 09:15:00');

        $depth = $backlog->named(QueueName::Transcripts);

        self::assertSame(QueueName::Transcripts, $depth->queue);
        self::assertSame($backlog, $depth->backlog);
    }
}
