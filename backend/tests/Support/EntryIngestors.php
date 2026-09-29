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
    public static function build(EntityManagerInterface $em, ClockInterface $clock): EntryIngestor
    {
        return self::withPlatformRules($em, $clock, new PlatformEntryRules([]));
    }

    public static function withPlatformRules(
        EntityManagerInterface $em,
        ClockInterface $clock,
        PlatformEntryRules $platformRules,
    ): EntryIngestor {
        /** @var EntryRepository $entryRepository */
        $entryRepository = $em->getRepository(Entry::class);
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $em->getRepository(Category::class);
        $imageWriter = new EntryImageWriter(new NaiveUtcClock($clock));

        return new EntryIngestor(
            $em,
            $entryRepository,
            new UrlNormalizer(),
            new EntryCategoryWriter($em, $categoryRepository, new CategoryNormalizer()),
            $platformRules,
            new IngestedEntryFactory(new EntrySanitizer(new TrailingBlankRemover()), $imageWriter),
            $imageWriter,
        );
    }
}
