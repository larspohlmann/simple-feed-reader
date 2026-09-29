<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Repository\OrphanedFeedRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Service\Feed\OrphanedFeedReclaimer;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\FaviconResolver\FaviconResolver;
use App\Service\Parser\Factory\FeedParserFactory;
use App\Service\Parser\FeedParser;
use App\Service\Refresh\ContentChangeMarker\ContentChangeMarkerInterface;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedBodyParser\ScrapedBodyParser;
use App\Service\Refresh\FeedBodyParser\XmlBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\MissingFaviconResolver;
use App\Service\Refresh\RefreshHousekeeping;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Retention\EntryPruner;
use App\Service\Scraper\HtmlItemExtractor;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** A RefreshRunner over the EntityManager's real repositories: the one assembly the refresh and tick tests share. */
final readonly class RefreshRunners
{
    private function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private FeedBodyParser $bodyParser,
        private EntityManagerInterface $flushingEntityManager,
        private LockFactory $lockFactory,
        private ContentChangeMarkerInterface $changeMarker,
        private SearchIndexWriterInterface $indexWriter,
    ) {
    }

    public static function fromContainer(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): self {
        return new self(
            $entityManager,
            $clock,
            self::bodyParser($container),
            $entityManager,
            new LockFactory(new InMemoryStore()),
            new RecordingContentChangeMarker(),
            new RecordingSearchIndexWriter(),
        );
    }

    /** Only the outcome and favicon flushes go through this EntityManager; the repositories keep the real one. */
    public function flushingThrough(EntityManagerInterface $flushingEntityManager): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $this->indexWriter,
        );
    }

    public function lockingWith(LockFactory $lockFactory): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $this->flushingEntityManager,
            $lockFactory,
            $this->changeMarker,
            $this->indexWriter,
        );
    }

    public function markingChangesOn(ContentChangeMarkerInterface $changeMarker): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $this->flushingEntityManager,
            $this->lockFactory,
            $changeMarker,
            $this->indexWriter,
        );
    }

    public function indexingInto(SearchIndexWriterInterface $indexWriter): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $this->flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $indexWriter,
        );
    }

    public function build(
        BatchFeedFetcherInterface $feedFetcher,
        BatchFeedFetcherInterface $homepageFetcher,
    ): RefreshRunner {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->entityManager->getRepository(Feed::class);
        $indexer = new EntryIndexer($this->indexWriter, new NullLogger());

        return new RefreshRunner(
            $feedRepository,
            $feedFetcher,
            new FeedOutcomePersister(
                $this->flushingEntityManager,
                $feedRepository,
                $this->bodyParser,
                EntryIngestors::build($this->entityManager, $this->clock),
                FeedSchedulers::build($this->clock),
                $indexer,
                new NullLogger(),
            ),
            new MissingFaviconResolver(
                new FaviconResolver($homepageFetcher, new NullLogger()),
                $this->flushingEntityManager,
            ),
            new RefreshHousekeeping(
                new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->entityManager)),
                new EntryPruner(
                    new RetentionRepository($this->entityManager, new RowIds($this->entityManager)),
                    $this->clock,
                    $indexer,
                ),
                new NullLogger(),
            ),
            $this->lockFactory,
            $this->clock,
            new NullLogger(),
            $this->changeMarker,
        );
    }

    /** The keys the container's tagged locator carries; FeedBodyParserWiringTest proves the container routes alike. */
    private static function bodyParser(ContainerInterface $container): FeedBodyParser
    {
        $extractor = $container->get(HtmlItemExtractor::class);

        return new FeedBodyParser(new ServiceLocator([
            XmlBodyParser::format() => static fn (): XmlBodyParser => new XmlBodyParser(
                new FeedParser(new FeedParserFactory([
                    FeedFormatParsers::rss2(),
                    FeedFormatParsers::atom10(),
                    FeedFormatParsers::atom03(),
                    FeedFormatParsers::rss1(),
                ])),
            ),
            ScrapedBodyParser::format() => static fn (): ScrapedBodyParser => new ScrapedBodyParser($extractor),
        ]));
    }
}
