<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Reading;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\User;
use App\Repository\SharingSettingsRepository;
use App\Service\Library\Reading\EntryShareRecorder;
use App\Service\Library\Reading\EntryShares;
use App\Tests\DbTestCase;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\FailingEntryShareStore;
use App\Tests\Support\UserFactory;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** A share the network already accepted must not turn into a failed request: a retried quote posts twice (#2007). */
final class EntryShareRecorderTest extends DbTestCase
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
            new EntryShares(
                new FailingEntryShareStore(),
                new MockClock('2026-11-03 10:00:00'),
                $this->sharingSettings(),
            ),
            $logger,
        );
        $user = $this->user();

        $recorder->record($user, $this->entry());

        self::assertCount(1, $logger->records);
        [$level, , $context] = $logger->records[0];
        self::assertSame('warning', $level);
        self::assertSame($user->requireId(), $context['userId']);
        self::assertSame(11, $context['entryId']);
        self::assertInstanceOf(\Throwable::class, $context['exception']);
    }

    private function user(): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->entityManager, $hasher))->create('recorder@example.test');
    }

    private function sharingSettings(): SharingSettingsRepository
    {
        $repository = self::getContainer()->get(SharingSettingsRepository::class);
        self::assertInstanceOf(SharingSettingsRepository::class, $repository);

        return $repository;
    }

    private function entry(): Entry
    {
        $at = new \DateTimeImmutable('2026-11-01');
        $entry = new Entry(new Feed('https://recorder.example/feed.xml'), 'guid', null, 'Title', $at, $at);

        return self::withId($entry, 11);
    }
}
