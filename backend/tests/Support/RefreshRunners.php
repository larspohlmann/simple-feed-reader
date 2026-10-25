<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\RetentionSetting;
use App\Entity\SubscriptionDay;
use App\Repository\FeedRepository;
use App\Repository\OrphanedFeedRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Repository\SubscriptionActivityRepository;
use App\Repository\SubscriptionDayRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Feed\OrphanedFeedReclaimer;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\FaviconResolver\FaviconResolver;
use App\Service\Parser\Factory\FeedParserFactory;
use App\Service\Parser\FeedParser;
use App\Service\Refresh\ContentChangeMarker\ContentChangeMarkerInterface;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedBodyParser\ScrapedBodyParser;
use App\Service\Refresh\FeedBodyParser\XmlBodyParser;
use App\Service\Refresh\MissingFaviconResolver;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;
use App\Service\Refresh\RefreshHousekeeping;
use App\Service\Refresh\RefreshPassRecorder;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Retention\EntryPruner;
use App\Service\Retention\RetentionSettings;
use App\Service\Scraper\HtmlItemExtractor;
use App\Service\Search\EntryIndexer;
use App\Service\SubscriptionHistory\LiveDayCounter;
use App\Service\SubscriptionHistory\RetentionHorizon;
use App\Service\SubscriptionHistory\SubscriptionHistoryRecorder;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
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
        );
    }

    public function build(
        PassFetcherInterface $passFetcher,
        BatchFeedFetcherInterface $homepageFetcher,
    ): RefreshRunner {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->entityManager->getRepository(Feed::class);
        $indexer = new EntryIndexer($this->indexWriter, new NullLogger());
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
                new EntryPruner(
                    new RetentionRepository($this->entityManager, new RowIds($this->entityManager)),
                    new RetentionSettings(
                        $this->entityManager->getRepository(RetentionSetting::class),
                        $this->entityManager,
                    ),
                    $this->clock,
                    $indexer,
                ),
                $this->historyRecorder(),
                new NullLogger(),
            ),
            $this->lockFactory,
            $this->clock,
            $this->logger,
            $this->changeMarker,
        );
    }

    private function historyRecorder(): SubscriptionHistoryRecorder
    {
        $settings = new RetentionSettings(
            $this->entityManager->getRepository(RetentionSetting::class),
            $this->entityManager,
        );
        $activity = new SubscriptionActivityRepository($this->entityManager);
        /** @var SubscriptionDayRepository $storedDays */
        $storedDays = $this->entityManager->getRepository(SubscriptionDay::class);

        return new SubscriptionHistoryRecorder(
            $activity,
            $storedDays,
            new LiveDayCounter($activity),
            new RetentionHorizon($settings),
            new NaiveUtcClock($this->clock),
            $this->entityManager,
        );
    }

    /** The keys the container's tagged locator carries; FeedBodyParserWiringTest proves the container routes alike. */
    private static function bodyParser(ContainerInterface $container): FeedBodyParser
    {
        $extractor = $container->get(HtmlItemExtractor::class);

        return new FeedBodyParser(new ServiceLocator([
            XmlBodyParser::format() => static fn (): XmlBodyParser => new XmlBodyParser(
                new FeedParser(new FeedParserFactory(FeedFormatParsers::all())),
            ),
            ScrapedBodyParser::format() => static fn (): ScrapedBodyParser => new ScrapedBodyParser($extractor),
        ]));
    }
}
