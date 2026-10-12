<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\LinkCard;
use App\Entity\ShareEnrichment;
use App\Repository\LinkCardRepository;
use App\Repository\ShareEnrichmentRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\BlueskyPendingEmbed;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriter;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\PostEnricher;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Completion\KnownShareSettler;
use App\Service\Completion\ShareEnrichmentQueue;
use App\Service\Fetch\HostThrottle;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Service\Share\EntryShareClassifier;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PostEnrichers
{
    private function __construct()
    {
    }

    public static function build(
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        AppViewClient $appView,
        LoggerInterface $logger,
        SearchIndexWriterInterface $indexWriter = new RecordingSearchIndexWriter(),
    ): PostEnricher {
        $naiveUtcClock = new NaiveUtcClock($clock);

        return new PostEnricher(
            $entityManager,
            self::enrichments($entityManager),
            $appView,
            self::embedWriter($entityManager),
            new EntryIndexer($indexWriter, new NullLogger()),
            $naiveUtcClock,
            $logger,
        );
    }

    /** An enricher whose every AppView call fails, unlogged: for refresh tests that need no AppView answer. */
    public static function idle(EntityManagerInterface $entityManager, ClockInterface $clock): PostEnricher
    {
        $appView = new AppViewClient(new StubFeedFetcher(), new HostThrottle(new ArrayAdapter(clock: $clock), $clock));

        return self::build($entityManager, $clock, $appView, new NullLogger());
    }

    public static function queue(EntityManagerInterface $entityManager, ClockInterface $clock): ShareEnrichmentQueue
    {
        return new ShareEnrichmentQueue(
            $entityManager,
            new NaiveUtcClock($clock),
            new EntryShareClassifier(new BlueskyPendingEmbed(), new EntrySanitizer(new TrailingBlankRemover())),
            new BlueskyPendingEmbed(),
            new KnownShareSettler(
                $entityManager,
                self::embedWriter($entityManager),
                ShareLinkCardsWiring::cardWriter($entityManager),
                new NullLogger(),
            ),
        );
    }

    public static function embedWriter(EntityManagerInterface $entityManager): EntryEmbedWriter
    {
        return new EntryEmbedWriter(
            new PostEmbedRenderer(),
            new EntrySanitizer(new TrailingBlankRemover()),
            new EntryImageWriter(),
            self::linkCards($entityManager),
        );
    }

    public static function enrichments(EntityManagerInterface $entityManager): ShareEnrichmentRepository
    {
        /** @var ShareEnrichmentRepository $enrichments */
        $enrichments = $entityManager->getRepository(ShareEnrichment::class);

        return $enrichments;
    }

    public static function linkCards(EntityManagerInterface $entityManager): LinkCardRepository
    {
        /** @var LinkCardRepository $linkCards */
        $linkCards = $entityManager->getRepository(LinkCard::class);

        return $linkCards;
    }
}
