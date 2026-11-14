<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\RetentionSetting;
use App\Repository\FeedRepository;
use App\Repository\OrphanedFeedRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Service\Ingestion\Feed\OrphanedFeedReclaimer;
use App\Service\Outbound\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Outbound\Fetch\FaviconResolver\FaviconResolver;
use App\Service\Ingestion\Parser\Factory\FeedParserFactory;
use App\Service\Ingestion\Parser\FeedParser;
use App\Service\Ingestion\Refresh\ContentChangeMarker\ContentChangeMarkerInterface;
use App\Service\Ingestion\Refresh\FeedBodyParser;
use App\Service\Ingestion\Refresh\FeedBodyParser\ScrapedBodyParser;
use App\Service\Ingestion\Refresh\FeedBodyParser\XmlBodyParser;
use App\Service\Ingestion\Refresh\MissingFaviconResolver;
use App\Service\Ingestion\Refresh\PassFetcher\PassFetcherInterface;
use App\Service\Ingestion\Refresh\RefreshHousekeeping;
use App\Service\Ingestion\Refresh\RefreshPassRecorder;
use App\Service\Ingestion\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Ingestion\Retention\EntryPruner;
use App\Service\Ingestion\Retention\RetentionSettings;
use App\Service\Ingestion\Scraper\HtmlItemExtractor;
use App\Service\Library\Search\EntryIndexer;
use App\Service\Library\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Tests\Service\Library\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
        private FeedOutcomePersisters $outcomePersisters,
        private EntityManagerInterface $flushingEntityManager,
        private LockFactory $lockFactory,
        private ContentChangeMarkerInterface $changeMarker,
        private SearchIndexWriterInterface $indexWriter,
        private LoggerInterface $logger,
        private ?int $maxEntriesPerFeed = null,
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
            new FeedOutcomePersisters(
                $entityManager,
                $clock,
                self::bodyParser($container),
                FeedOutcomePersisters::timelineSelfThreads($container),
            ),
            $entityManager,
            new LockFactory(new InMemoryStore()),
            new RecordingContentChangeMarker(),
            new RecordingSearchIndexWriter(),
            new NullLogger(),
        );
    }

    /** Only the outcome and favicon flushes go through this EntityManager; the repositories keep the real one. */
    public function flushingThrough(EntityManagerInterface $flushingEntityManager): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->outcomePersisters,
            $flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $this->indexWriter,
            $this->logger,
            $this->maxEntriesPerFeed,
        );
    }

    public function lockingWith(LockFactory $lockFactory): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->outcomePersisters,
            $this->flushingEntityManager,
            $lockFactory,
            $this->changeMarker,
            $this->indexWriter,
            $this->logger,
            $this->maxEntriesPerFeed,
        );
    }

    public function markingChangesOn(ContentChangeMarkerInterface $changeMarker): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->outcomePersisters,
            $this->flushingEntityManager,
            $this->lockFactory,
            $changeMarker,
            $this->indexWriter,
            $this->logger,
            $this->maxEntriesPerFeed,
        );
    }

    public function indexingInto(SearchIndexWriterInterface $indexWriter): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->outcomePersisters,
            $this->flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $indexWriter,
            $this->logger,
            $this->maxEntriesPerFeed,
        );
    }

    public function loggingTo(LoggerInterface $logger): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->outcomePersisters,
            $this->flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $this->indexWriter,
            $logger,
            $this->maxEntriesPerFeed,
        );
    }

    public function cappingFeedsAt(int $maxEntriesPerFeed): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->outcomePersisters,
            $this->flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $this->indexWriter,
            $this->logger,
            $maxEntriesPerFeed,
        );
    }

    public function build(
        PassFetcherInterface $passFetcher,
        BatchFeedFetcherInterface $homepageFetcher,
    ): RefreshRunner {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->entityManager->getRepository(Feed::class);
        $indexer = EntryIndexers::with($this->indexWriter);
        $retentionSettings = new RetentionSettings(
            $this->entityManager->getRepository(RetentionSetting::class),
            $this->entityManager,
        );
        $outcomePersister = $this->outcomePersisters
            ->flushingThrough($this->flushingEntityManager)
            ->indexingInto($this->indexWriter)
            ->idle();

        return new RefreshRunner(
            $feedRepository,
            new RefreshPassRecorder($passFetcher, $outcomePersister, SocialFeeds::repository($this->entityManager)),
            new MissingFaviconResolver(
                new FaviconResolver($homepageFetcher, new NullLogger()),
                $this->flushingEntityManager,
            ),
            new RefreshHousekeeping(
                new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->entityManager)),
                $this->pruner($retentionSettings, $indexer),
                new SubscriptionHistoryWiring($this->entityManager, $retentionSettings, $this->clock)->recorder(),
                new NullLogger(),
            ),
            $this->lockFactory,
            $this->clock,
            $this->logger,
            $this->changeMarker,
        );
    }

    private function pruner(RetentionSettings $settings, EntryIndexer $indexer): EntryPruner
    {
        $retention = new RetentionRepository($this->entityManager, new RowIds($this->entityManager));
        if ($this->maxEntriesPerFeed === null) {
            return new EntryPruner($retention, $settings, $this->clock, $indexer);
        }

        return new EntryPruner($retention, $settings, $this->clock, $indexer, $this->maxEntriesPerFeed);
    }

    /** The keys the container's tagged locator carries; FeedBodyParserWiringTest proves the container routes alike. */
    private static function bodyParser(ContainerInterface $container): FeedBodyParser
    {
        $extractor = $container->get(HtmlItemExtractor::class);

        return new FeedBodyParser(new ServiceLocator([
            TaggedItemOf::index(XmlBodyParser::class) => static fn (): XmlBodyParser => new XmlBodyParser(
                new FeedParser(new FeedParserFactory(FeedFormatParsers::all())),
            ),
            TaggedItemOf::index(ScrapedBodyParser::class)
                => static fn (): ScrapedBodyParser => new ScrapedBodyParser($extractor),
        ]));
    }
}
