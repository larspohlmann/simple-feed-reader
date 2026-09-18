<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\EntryBatchInserter;
use App\Service\Backup\RestoreEntryLoader;
use App\Service\Backup\RestoreFeedTargets;
use App\Service\Search\EntryIndexer;
use App\Service\Url\UrlNormalizer;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * The read-back contract of #456, pinned directly: after a batch insert the
 * loader asks for the ids of exactly the hashes it wrote. AccountRestorerTest
 * proves the same path end to end; this test is the one that fails when a
 * refactor quietly goes back to re-reading the whole feed.
 */
final class RestoreEntryLoaderTest extends TestCase
{
    private const string FEED_URL = 'https://one.example/feed.xml';

    public function testTheIdReadBackAsksOnlyForTheHashesItJustInserted(): void
    {
        $known = $this->line('guid-known');
        $fresh = $this->line('guid-fresh');
        $entries = $this->createMock(EntryRepository::class);
        $entries->method('guidHashToIdMapForFeed')->with(7)->willReturn([$known->guidHash => 1]);
        $entries->expects(self::once())
            ->method('entryIdsByGuidHash')
            ->with(7, [$fresh->guidHash])
            ->willReturn([$fresh->guidHash => 42]);
        $entries->method('entriesAfterId')->willReturn([]);
        $loader = $this->loader($entries);
        $loader->begin($this->targets($entries), $this->user());

        $loader->bufferEntry($known);
        $loader->bufferEntry($fresh);
        $loader->finish();

        self::assertSame(1, $loader->entriesCreated());
    }

    public function testAReadBackThatMissesARowItJustWroteIsALogicError(): void
    {
        $fresh = $this->line('guid-fresh');
        $entries = $this->createStub(EntryRepository::class);
        $entries->method('entryIdsByGuidHash')->willReturn([]);
        $entries->method('guidHashToIdMapForFeed')->willReturn([]);
        $loader = $this->loader($entries);
        $loader->begin($this->targets($entries), $this->user());

        $loader->bufferEntry($fresh);

        $this->expectException(\LogicException::class);
        $loader->finish();
    }

    private function loader(EntryRepository $entries): RestoreEntryLoader
    {
        return new RestoreEntryLoader(
            $this->createStub(EntityManagerInterface::class),
            $entries,
            $this->createStub(EntryStateRepository::class),
            new EntryBatchInserter($this->createStub(Connection::class), new UrlNormalizer()),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            new MockClock('2026-08-01 00:00:00', 'UTC'),
        );
    }

    /**
     * A real RestoreFeedTargets over stubbed repositories: this feed url
     * resolves to feed id 7, unread by any other account, so the target it
     * lazily builds behaves exactly like the hand-built RestoreFeedTarget this
     * test used before RestoreFeedTargets existed.
     */
    private function targets(EntryRepository $entries): RestoreFeedTargets
    {
        $subscriptions = $this->createStub(SubscriptionRepository::class);
        $subscriptions->method('feedIdsByUrlForUser')->willReturn([self::FEED_URL => 7]);
        $feeds = $this->createStub(FeedRepository::class);
        $feeds->method('isReadByAnotherUser')->willReturn(false);

        return new RestoreFeedTargets(1, $subscriptions, $feeds, $entries);
    }

    private function user(): User
    {
        return new User('loader@example.com', new \DateTimeImmutable('2026-08-01'));
    }

    private function line(string $guid): EntryLine
    {
        return new EntryLine(
            feedUrl: self::FEED_URL,
            guid: $guid,
            guidHash: hash('sha256', $guid),
            url: 'https://example.test/' . $guid,
            title: 'Title ' . $guid,
            author: null,
            summary: null,
            contentHtml: null,
            imageUrl: null,
            imageWidth: null,
            imageHeight: null,
            publishedAt: null,
            createdAt: new \DateTimeImmutable('2026-08-02 06:00:00'),
            effectiveDate: new \DateTimeImmutable('2026-08-02 05:00:00'),
        );
    }
}
