<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryLemmyPost;
use App\Repository\CategoryRepository;
use App\Repository\EntryRepository;
use App\Service\Ingestion\Category\CategoryNormalizer;
use App\Service\Ingestion\Ingest\EntryCategoryWriter;
use App\Service\Ingestion\Ingest\EntryImageWriter;
use App\Service\Ingestion\Ingest\EntryIngestor;
use App\Service\Ingestion\Ingest\EntryLemmyPostWriter;
use App\Service\Ingestion\Ingest\FeedMetadataRecorder;
use App\Service\Ingestion\Ingest\StoredEntryRefresher;
use App\Service\Ingestion\Ingest\EntryTranscriptWriter;
use App\Service\Ingestion\Ingest\Factory\IngestedEntryFactory;
use App\Service\Ingestion\Ingest\PlatformEntryRules;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Sanitize\TrailingBlankRemover;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\FeedLanguageResolver;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Social\Social\ProfileFeedTitle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** An EntryIngestor over the EntityManager's real repositories: the one assembly the ingest and refresh tests share. */
final class EntryIngestors
{
    public static function build(EntityManagerInterface $entityManager, ClockInterface $clock): EntryIngestor
    {
        return self::withPlatformRules($entityManager, $clock, new PlatformEntryRules([]));
    }

    public static function withPlatformRules(
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        PlatformEntryRules $platformRules,
    ): EntryIngestor {
        /** @var EntryRepository $entryRepository */
        $entryRepository = $entityManager->getRepository(Entry::class);
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $entityManager->getRepository(Category::class);

        return new EntryIngestor(
            $entityManager,
            $entryRepository,
            EntryUrlHashes::production(),
            new EntryCategoryWriter($entityManager, $categoryRepository, new CategoryNormalizer()),
            $platformRules,
            new IngestedEntryFactory(
                new EntrySanitizer(new TrailingBlankRemover()),
                new EntryImageWriter(),
                LanguageWiring::socialEntryLanguage(),
            ),
            new FeedMetadataRecorder(
                new ProfileFeedTitle(SocialRuleWiring::profileFeeds()),
                new FeedLanguageResolver(new LanguageGuess(new LanguageDetectionFactory()->create())),
            ),
            new EntryTranscriptWriter($entityManager),
            self::lemmyPostWriter($entityManager),
        );
    }

    public static function refresher(
        EntityManagerInterface $entityManager,
        PlatformEntryRules $platformRules,
    ): StoredEntryRefresher {
        /** @var EntryRepository $entryRepository */
        $entryRepository = $entityManager->getRepository(Entry::class);

        return new StoredEntryRefresher(
            $entryRepository,
            $platformRules,
            new EntryImageWriter(),
            self::lemmyPostWriter($entityManager),
        );
    }

    private static function lemmyPostWriter(EntityManagerInterface $entityManager): EntryLemmyPostWriter
    {
        return new EntryLemmyPostWriter($entityManager, $entityManager->getRepository(EntryLemmyPost::class));
    }
}
