<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\LinkCard;
use App\Entity\PostEnrichment;
use App\Repository\LinkCardRepository;
use App\Repository\PostEnrichmentRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriter;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\PostEnricher;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Completion\PostEnrichmentQueue;
use App\Service\Fetch\HostThrottle;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
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
    ): PostEnricher {
        $naiveUtcClock = new NaiveUtcClock($clock);

        return new PostEnricher(
            $entityManager,
            self::enrichments($entityManager),
            $appView,
            new EntryEmbedWriter(
                new PostEmbedRenderer(),
                new EntrySanitizer(new TrailingBlankRemover()),
                new EntryImageWriter(),
                self::linkCards($entityManager),
            ),
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

    public static function queue(EntityManagerInterface $entityManager, ClockInterface $clock): PostEnrichmentQueue
    {
        return new PostEnrichmentQueue(
            $entityManager,
            self::enrichments($entityManager),
            new EntrySanitizer(new TrailingBlankRemover()),
            new NaiveUtcClock($clock),
        );
    }

    public static function enrichments(EntityManagerInterface $entityManager): PostEnrichmentRepository
    {
        /** @var PostEnrichmentRepository $enrichments */
        $enrichments = $entityManager->getRepository(PostEnrichment::class);

        return $enrichments;
    }

    public static function linkCards(EntityManagerInterface $entityManager): LinkCardRepository
    {
        /** @var LinkCardRepository $linkCards */
        $linkCards = $entityManager->getRepository(LinkCard::class);

        return $linkCards;
    }
}
