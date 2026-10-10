<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\PendingPostEnrichment;
use App\Repository\PendingPostEnrichmentRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\EntryEmbedWriter;
use App\Service\Bluesky\PendingPostQueue;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\PostEnricher;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\HostThrottle;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** A PostEnricher over the EntityManager's real repository: the one assembly the enrichment and refresh tests share. */
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
        /** @var PendingPostEnrichmentRepository $pendingPosts */
        $pendingPosts = $entityManager->getRepository(PendingPostEnrichment::class);
        $naiveUtcClock = new NaiveUtcClock($clock);

        return new PostEnricher(
            $entityManager,
            $pendingPosts,
            new PendingPostQueue($entityManager, $naiveUtcClock),
            $appView,
            new EntryEmbedWriter(
                new PostEmbedRenderer(),
                new EntrySanitizer(new TrailingBlankRemover()),
                new EntryImageWriter(),
            ),
            $naiveUtcClock,
            $logger,
        );
    }

    /** For refresh tests without Bluesky posts: an AppView call would fail, unlogged. */
    public static function idle(EntityManagerInterface $entityManager, ClockInterface $clock): PostEnricher
    {
        $appView = new AppViewClient(new StubFeedFetcher(), new HostThrottle(new ArrayAdapter(clock: $clock), $clock));

        return self::build($entityManager, $clock, $appView, new NullLogger());
    }
}
