<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Feed;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\EntryBatchInserter;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Backup\RestoreEntryLoader;
use App\Service\Backup\RestoreLoadPass;
use App\Service\Search\EntryIndexer;
use App\Service\Url\UrlNormalizer;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Two narrow unit tests. The first pins the one-query feed lookup of #455
 * (AccountRestorerTest proves the same path end to end). The second covers
 * the one branch AccountRestorerTest can no longer reach through content:
 * #412's final review closed every route by which a
 * crafted-but-otherwise-valid backup file could still make the database
 * refuse a value (BackupTally now catches a duplicate tag, feed or
 * subscription in pass 1; RestoreEntryLoader dedupes a repeated entry line by
 * design; a repeated entry_state line collides in Doctrine's own identity
 * map before it reaches SQL). What is left of "the database rejects a value"
 * is a driver failure with no content behind it at all — a schema mismatch,
 * a dropped connection, a column too narrow for a title the grammar never
 * bounds. That is not reproducible through the real service graph without
 * corrupting the schema mid-test, which itself breaks the MySQL leg's
 * transactional test isolation. A fake EntityManager whose flush() throws is
 * the direct way to prove RestoreLoadPass still wraps it.
 */
final class RestoreLoadPassTest extends TestCase
{
    public function testTheFeedLookupRunsOnceForTheWholeFileAndCreatesWhatItMisses(): void
    {
        $user = new User('one-lookup@example.com', new \DateTimeImmutable('2026-08-01'));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getReference')->willReturn($user);
        $feeds = $this->createMock(FeedRepository::class);
        $feeds->expects(self::once())
            ->method('findByUrlsIndexedByUrl')
            ->with(['https://known.example/feed.xml', 'https://new.example/feed.xml'])
            ->willReturn(['https://known.example/feed.xml' => new Feed('https://known.example/feed.xml')]);
        $feeds->expects(self::never())->method('findOneBy');
        $pass = new RestoreLoadPass(
            $em,
            $feeds,
            $this->createStub(EntryRepository::class),
            $this->harmlessEntryLoader($em),
        );

        $result = $pass->run($user, (function () {
            yield $this->feedLine('https://known.example/feed.xml');
            yield $this->feedLine('https://new.example/feed.xml');
            yield $this->subscriptionLine('https://known.example/feed.xml');
            yield $this->subscriptionLine('https://new.example/feed.xml');
        })());

        self::assertSame(1, $result->feeds);
        self::assertSame(2, $result->subscriptions);
    }

    public function testFeedsWithNoSubscriptionAreStillResolvedAtTheEntryPhase(): void
    {
        $user = new User('feeds-only@example.com', new \DateTimeImmutable('2026-08-01'));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getReference')->willReturn($user);
        $feeds = $this->createMock(FeedRepository::class);
        $feeds->expects(self::once())
            ->method('findByUrlsIndexedByUrl')
            ->with(['https://orphan-one.example/feed.xml', 'https://orphan-two.example/feed.xml'])
            ->willReturn([]);
        $pass = new RestoreLoadPass(
            $em,
            $feeds,
            $this->createStub(EntryRepository::class),
            $this->harmlessEntryLoader($em),
        );

        // No subscription line ever runs, so loadSubscription() never gets a
        // chance to call resolveHeldFeeds() itself — only startEntryPhase()
        // can still resolve (and create) these feeds (#455).
        $result = $pass->run($user, (function () {
            yield $this->feedLine('https://orphan-one.example/feed.xml');
            yield $this->feedLine('https://orphan-two.example/feed.xml');
        })());

        self::assertSame(2, $result->feeds);
        self::assertSame(0, $result->subscriptions);
    }

    private function feedLine(string $url): FeedLine
    {
        return new FeedLine(
            url: $url,
            siteUrl: null,
            title: null,
            description: null,
            faviconUrl: null,
            imageUrl: null,
            sourceFormat: 'xml',
        );
    }

    private function subscriptionLine(string $feedUrl): SubscriptionLine
    {
        return new SubscriptionLine(
            feedUrl: $feedUrl,
            customTitle: null,
            position: 0,
            markedReadUntil: null,
            createdAt: new \DateTimeImmutable('2026-07-01 08:00:00'),
            tags: [],
            includeInAllItems: true,
            includeInForYou: true,
        );
    }

    public function testADatabaseFailureDuringTheAccountShapeFlushIsAWrappedBackupError(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($this->dbalException());

        $pass = new RestoreLoadPass(
            $em,
            $this->createStub(FeedRepository::class),
            $this->createStub(EntryRepository::class),
            $this->harmlessEntryLoader($em),
        );

        $this->expectException(BackupLoadFailedException::class);
        $pass->run(new User('flush-fails@example.com', new \DateTimeImmutable('2026-08-01')), (function () {
            yield from [];
        })());
    }

    /**
     * A real RestoreEntryLoader, wired to test doubles throughout: it is
     * never actually called in the flush-fails scenario (the account-shape
     * flush throws first), but RestoreLoadPass's constructor takes the
     * concrete class, so it needs a valid instance rather than a mock of a
     * `final` one.
     */
    private function harmlessEntryLoader(EntityManagerInterface $em): RestoreEntryLoader
    {
        return new RestoreEntryLoader(
            $em,
            $this->createStub(EntryRepository::class),
            new EntryBatchInserter($this->createStub(Connection::class), new UrlNormalizer()),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            new MockClock('2026-08-01 00:00:00', 'UTC'),
        );
    }

    private function dbalException(): DbalException
    {
        return new class ('the database rejected a value') extends \Exception implements DbalException {
        };
    }
}
