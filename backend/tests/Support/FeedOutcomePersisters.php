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
use App\Service\Search\EntryIndexer;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;

/** A FeedOutcomePersister over the real repositories, the Bluesky rule included; each seam has a wither. */
final class FeedOutcomePersisters
{
    private EntityManagerInterface $flushingEntityManager;
    private SearchIndexWriterInterface $indexWriter;
    private LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly FeedBodyParser $bodyParser,
    ) {
        $this->flushingEntityManager = $entityManager;
        $this->indexWriter = new RecordingSearchIndexWriter();
        $this->logger = new NullLogger();
    }

    public static function fromContainer(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): self {
        $bodyParser = $container->get(FeedBodyParser::class);
        Assert::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new self($entityManager, $clock, $bodyParser);
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
            new EntryIndexer($this->indexWriter, new NullLogger()),
            PostEnrichers::queue($this->entityManager, $this->clock),
            new FeedShareEnrichment($postEnricher, SelfThreadEnrichers::idle($this->entityManager, $this->clock)),
            $this->logger,
        );
    }
}
