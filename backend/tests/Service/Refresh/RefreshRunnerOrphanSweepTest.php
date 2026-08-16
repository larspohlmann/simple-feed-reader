<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Service\EntryIngestor;
use App\Service\EntryPruner;
use App\Service\EntrySanitizer;
use App\Service\FeedScheduler;
use App\Service\Fetch\FaviconResolver;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Parser\Atom03Parser;
use App\Service\Parser\Atom10Parser;
use App\Service\Parser\FeedParser;
use App\Service\Parser\FeedParserFactory;
use App\Service\Parser\Rss1Parser;
use App\Service\Parser\Rss2Parser;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use App\Service\Refresh\ScrapedBodyParser;
use App\Service\Refresh\XmlBodyParser;
use App\Service\Scraper\HtmlItemExtractor;
use App\Service\Search\EntryIndexer;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\StubFeedFetcher;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The safety net behind the immediate reclaim: a pruning refresh must sweep
 * away every feed nobody subscribes to, and a user-triggered refresh must
 * leave them alone so it stays fast.
 */
final class RefreshRunnerOrphanSweepTest extends DbTestCase
{
    private MockClock $clock;
    private StubFeedFetcher $fetcher;
    private StubFeedFetcher $faviconFetcher;
    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->fetcher = new StubFeedFetcher($this->clock);
        $this->faviconFetcher = new StubFeedFetcher();
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    private function indexer(): EntryIndexer
    {
        return new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger());
    }

    private function runner(): RefreshRunner
    {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->em->getRepository(Feed::class);
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);
        $extractor = self::getContainer()->get(HtmlItemExtractor::class);
        self::assertInstanceOf(HtmlItemExtractor::class, $extractor);

        return new RefreshRunner(
            $feedRepository,
            $this->em,
            $this->fetcher,
            new FeedBodyParser(new ServiceLocator([
                XmlBodyParser::format() => static fn (): XmlBodyParser => new XmlBodyParser(
                    new FeedParser(new FeedParserFactory([
                        new Rss2Parser(),
                        new Atom10Parser(),
                        new Atom03Parser(),
                        new Rss1Parser(),
                    ])),
                ),
                ScrapedBodyParser::format() => static fn (): ScrapedBodyParser => new ScrapedBodyParser($extractor),
            ])),
            new EntryIngestor($this->em, $entryRepository, new EntrySanitizer()),
            new FaviconResolver($this->faviconFetcher, new NullLogger()),
            new FeedScheduler($this->clock),
            new EntryPruner($this->em, $this->clock, $this->indexer()),
            new OrphanedFeedReclaimer($this->em),
            $this->indexer(),
            $this->lockFactory,
            $this->clock,
            new NullLogger(),
        );
    }

    public function testAPruningRefreshDeletesAnOrphanedFeed(): void
    {
        $orphan = new Feed('https://orphan.example.com/rss');
        $this->em->persist($orphan);
        $this->em->flush();
        $orphanId = (int) $orphan->getId();

        $this->runner()->run(RefreshRequest::allDue(budgetSeconds: 30));

        $this->em->clear();
        self::assertNull($this->em->getRepository(Feed::class)->find($orphanId));
    }

    public function testAUserRefreshLeavesAnOrphanedFeedAlone(): void
    {
        $orphan = new Feed('https://orphan-2.example.com/rss');
        $this->em->persist($orphan);
        $this->em->flush();
        $orphanId = (int) $orphan->getId();

        $this->runner()->run(RefreshRequest::forUser(userId: 1, budgetSeconds: 30));

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Feed::class)->find($orphanId));
    }
}
