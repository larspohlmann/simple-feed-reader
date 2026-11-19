<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\LinkCard;
use App\Entity\ShareEnrichment;
use App\Entity\SharedLink;
use App\Entity\SharedLinkResolution;
use App\Repository\LinkCardRepository;
use App\Repository\ShareEnrichmentRepository;
use App\Repository\SharedLinkRepository;
use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkCard\EntryLinks;
use App\Service\Content\LinkCard\SharedLinkWriter;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Language\PreviewLanguage;
use App\Service\Social\BlueskyPost\AppViewClient;
use App\Service\Social\BlueskyPost\BlueskyPendingEmbed;
use App\Service\Social\Social\MastodonPendingThread;
use App\Service\Social\BlueskyPost\EntryEmbedWriter\EntryEmbedWriter;
use App\Service\Social\BlueskyPost\PostEmbedRenderer;
use App\Service\Social\BlueskyPost\PostEnricher;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Completion\KnownShareSettler;
use App\Service\Social\Completion\ShareEnrichmentQueue;
use App\Service\Outbound\Fetch\HostThrottle;
use App\Service\Ingestion\Ingest\EntryImageWriter;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Sanitize\TrailingBlankRemover;
use App\Service\Library\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Service\Social\Share\EntryShareClassifier;
use App\Tests\Service\Library\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;

final class PostEnrichers
{
    private static ?PreviewLanguage $previewLanguage = null;

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
            EntryIndexers::with($indexWriter),
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
            new MastodonPendingThread(SocialRuleWiring::postAddresses()),
            new KnownShareSettler(
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
            self::entryLinks($entityManager),
        );
    }

    public static function entryLinks(EntityManagerInterface $entityManager): EntryLinks
    {
        return new EntryLinks(self::sharedLinkWriter($entityManager), self::linkCards($entityManager));
    }

    public static function sharedLinkWriter(
        EntityManagerInterface $entityManager,
        ClockInterface $clock = new NativeClock(),
    ): SharedLinkWriter {
        return new SharedLinkWriter(
            self::sharedLinks($entityManager),
            self::linkResolutions($entityManager),
            new UrlNormalizer(),
            self::$previewLanguage ??= new PreviewLanguage(
                new LanguageGuess(new LanguageDetectionFactory()->createForFeedLanguages()),
            ),
            new NaiveUtcClock($clock),
        );
    }

    public static function linkResolutions(EntityManagerInterface $entityManager): SharedLinkResolutionRepository
    {
        /** @var SharedLinkResolutionRepository $linkResolutions */
        $linkResolutions = $entityManager->getRepository(SharedLinkResolution::class);

        return $linkResolutions;
    }

    public static function sharedLinks(EntityManagerInterface $entityManager): SharedLinkRepository
    {
        /** @var SharedLinkRepository $sharedLinks */
        $sharedLinks = $entityManager->getRepository(SharedLink::class);

        return $sharedLinks;
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
