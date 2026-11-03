<?php

declare(strict_types=1);

namespace App\Tests\Service\Reading;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\User;
use App\Service\Reading\EntryShareRecorder;
use App\Service\Reading\EntryShares;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\FailingEntryShareStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

/** A share the network already accepted must not turn into a failed request: a retried quote posts twice (#2007). */
final class EntryShareRecorderTest extends TestCase
{
    use AssignsEntityIds;

    public function testAFailedRecordingIsLoggedAndSwallowed(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $recorder = new EntryShareRecorder(
            new EntryShares(new FailingEntryShareStore(), new MockClock('2026-11-03 10:00:00')),
            $logger,
        );

        $recorder->record($this->user(), $this->entry());

        self::assertCount(1, $logger->records);
        [$level, , $context] = $logger->records[0];
        self::assertSame('warning', $level);
        self::assertSame(7, $context['userId']);
        self::assertSame(11, $context['entryId']);
        self::assertInstanceOf(\Throwable::class, $context['exception']);
    }

    private function user(): User
    {
        return self::withId(new User('recorder@example.test', new \DateTimeImmutable('2026-11-01')), 7);
    }

    private function entry(): Entry
    {
        $at = new \DateTimeImmutable('2026-11-01');
        $entry = new Entry(new Feed('https://recorder.example/feed.xml'), 'guid', null, 'Title', $at, $at);

        return self::withId($entry, 11);
    }
}
