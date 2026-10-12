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
use App\Service\Search\EntryIndexer;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;

/** A FeedOutcomePersister over the real repositories and the container's body parsers, Bluesky rule included. */
final readonly class FeedOutcomePersisters
{
    public function __construct(
        private ContainerInterface $container,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function idle(): FeedOutcomePersister
    {
        return $this->enrichingWith(PostEnrichers::idle($this->entityManager, $this->clock));
    }

    public function enrichingWith(PostEnricher $postEnricher): FeedOutcomePersister
    {
        /** @var FeedRepository $feeds */
        $feeds = $this->entityManager->getRepository(Feed::class);
        $bodyParser = $this->container->get(FeedBodyParser::class);
        Assert::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new FeedOutcomePersister(
            $this->entityManager,
            $feeds,
            $bodyParser,
            EntryIngestors::withPlatformRules(
                $this->entityManager,
                $this->clock,
                new PlatformEntryRules([new BlueskyEntryRule()]),
            ),
            FeedSchedulers::build($this->clock),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            PostEnrichers::queue($this->entityManager, $this->clock),
            $postEnricher,
            new NullLogger(),
        );
    }
}
