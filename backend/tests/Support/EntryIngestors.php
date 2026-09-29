<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Category;
use App\Entity\Entry;
use App\Repository\CategoryRepository;
use App\Repository\EntryRepository;
use App\Service\Category\CategoryNormalizer;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Ingest\EntryCategoryWriter;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\Factory\IngestedEntryFactory;
use App\Service\Ingest\PlatformEntryRules;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Url\UrlNormalizer;
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
        $imageWriter = new EntryImageWriter(new NaiveUtcClock($clock));

        return new EntryIngestor(
            $entityManager,
            $entryRepository,
            new UrlNormalizer(),
            new EntryCategoryWriter($entityManager, $categoryRepository, new CategoryNormalizer()),
            $platformRules,
            new IngestedEntryFactory(new EntrySanitizer(new TrailingBlankRemover()), $imageWriter),
            $imageWriter,
        );
    }
}
