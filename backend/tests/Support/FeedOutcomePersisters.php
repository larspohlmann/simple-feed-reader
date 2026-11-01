<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Service\Bluesky\BlueskyEntryRule;
use App\Service\Bluesky\PostEnricher;
use App\Service\Ingest\PlatformEntryRules;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\FeedShareEnrichment;
use App\Service\Refresh\IncomingEntries;
use App\Service\Social\DeletedPostTombstones;
use App\Service\Social\SelfThreadEnricher;
use App\Service\Social\TimelineSelfThreads;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;

/** A FeedOutcomePersister over the real repositories, the Bluesky rule included; each seam has a wither. */
final class FeedOutcomePersisters
{
    private EntityManagerInterface $flushingEntityManager;
    private SearchIndexWriterInterface $indexWriter;
    private LoggerInterface $logger;
    private ?SelfThreadEnricher $selfThreadEnricher = null;
    private DeletedPostTombstones $tombstones;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly FeedBodyParser $bodyParser,
        private readonly TimelineSelfThreads $timelineSelfThreads,
    ) {
        $this->flushingEntityManager = $entityManager;
        $this->indexWriter = new RecordingSearchIndexWriter();
        $this->logger = new NullLogger();
        $this->tombstones = new DeletedPostTombstones(new ArrayAdapter(), $clock);
    }

    public static function fromContainer(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): self {
        $bodyParser = $container->get(FeedBodyParser::class);
        Assert::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new self($entityManager, $clock, $bodyParser, self::timelineSelfThreads($container));
    }

    public static function timelineSelfThreads(ContainerInterface $container): TimelineSelfThreads
    {
        $timelineSelfThreads = $container->get(TimelineSelfThreads::class);
        Assert::assertInstanceOf(TimelineSelfThreads::class, $timelineSelfThreads);

        return $timelineSelfThreads;
    }

    /** Only the outcome flushes go through this EntityManager; the repositories keep the real one. */
    public function flushingThrough(EntityManagerInterface $flushingEntityManager): self
    {
        $copy = clone $this;
        $copy->flushingEntityManager = $flushingEntityManager;

        return $copy;
    }

    public function indexingInto(SearchIndexWriterInterface $indexWriter): self
    {
        $copy = clone $this;
        $copy->indexWriter = $indexWriter;

        return $copy;
    }

    public function loggingTo(LoggerInterface $logger): self
    {
        $copy = clone $this;
        $copy->logger = $logger;

        return $copy;
    }

    public function foldingWith(SelfThreadEnricher $selfThreadEnricher): self
    {
        $copy = clone $this;
        $copy->selfThreadEnricher = $selfThreadEnricher;

        return $copy;
    }

    public function tombstones(): DeletedPostTombstones
    {
        return $this->tombstones;
    }

    public function idle(): FeedOutcomePersister
    {
        return $this->enrichingWith(PostEnrichers::idle($this->entityManager, $this->clock));
    }

    public function enrichingWith(PostEnricher $postEnricher): FeedOutcomePersister
    {
        /** @var FeedRepository $feeds */
        $feeds = $this->entityManager->getRepository(Feed::class);

        return new FeedOutcomePersister(
            $this->flushingEntityManager,
            $feeds,
            $this->bodyParser,
            EntryIngestors::withPlatformRules(
                $this->entityManager,
                $this->clock,
                new PlatformEntryRules([new BlueskyEntryRule()]),
            ),
            FeedSchedulers::build($this->clock),
            EntryIndexers::with($this->indexWriter),
            new IncomingEntries(
                PostEnrichers::queue($this->entityManager, $this->clock),
                $this->timelineSelfThreads,
                $this->tombstones,
            ),
            new FeedShareEnrichment(
                $postEnricher,
                $this->selfThreadEnricher ?? SelfThreadEnrichers::idle($this->entityManager, $this->clock),
            ),
            $this->logger,
        );
    }
}
