<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Repository\CategoryRepository;
use App\Service\Category\CategoryNormalizer;
use App\Service\Category\NormalizedCategory;
use App\Service\Parser\ParsedEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves feed-declared categories of newly created entries to shared
 * Category rows, then writes the per-entry links. Write-once: only for
 * entries ingest just created, never on refresh.
 */
final class EntryCategoryWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categories,
        private readonly CategoryNormalizer $normalizer,
    ) {
    }

    /**
     * @param list<array{0: Entry, 1: ParsedEntry}> $pairs
     */
    public function attach(array $pairs): void
    {
        $entriesWithCategories = $this->normalizeEach($pairs);
        $resolvedByIdentity = $this->resolveCategories($this->distinctCategories($entriesWithCategories));
        $this->writeLinks($entriesWithCategories, $resolvedByIdentity);
    }

    /**
     * @param list<array{0: Entry, 1: ParsedEntry}> $pairs
     *
     * @return list<array{0: Entry, 1: list<NormalizedCategory>}>
     */
    private function normalizeEach(array $pairs): array
    {
        return array_map(
            fn (array $pair): array => [$pair[0], $this->normalizer->normalize($pair[1]->categories)],
            $pairs,
        );
    }

    /**
     * @param list<array{0: Entry, 1: list<NormalizedCategory>}> $entriesWithCategories
     *
     * @return array<string, NormalizedCategory>
     */
    private function distinctCategories(array $entriesWithCategories): array
    {
        $distinct = [];
        foreach ($entriesWithCategories as [, $categories]) {
            foreach ($categories as $category) {
                $distinct[$category->identity()] = $category;
            }
        }

        return $distinct;
    }

    /**
     * @param array<string, NormalizedCategory> $distinct
     *
     * @return array<string, Category>
     */
    private function resolveCategories(array $distinct): array
    {
        $resolved = $this->categories->findExistingByIdentities(array_values($distinct));

        $persistedNew = false;
        foreach ($distinct as $identity => $category) {
            if (isset($resolved[$identity])) {
                continue;
            }
            $created = new Category($category->canonicalKey, $category->scheme);
            $this->entityManager->persist($created);
            $resolved[$identity] = $created;
            $persistedNew = true;
        }

        if ($persistedNew) {
            $this->entityManager->flush();
        }

        return $resolved;
    }

    /**
     * @param list<array{0: Entry, 1: list<NormalizedCategory>}> $entriesWithCategories
     * @param array<string, Category>                            $resolvedByIdentity
     */
    private function writeLinks(array $entriesWithCategories, array $resolvedByIdentity): void
    {
        foreach ($entriesWithCategories as [$entry, $categories]) {
            foreach ($categories as $position => $category) {
                $this->entityManager->persist(new EntryCategory(
                    $entry,
                    $resolvedByIdentity[$category->identity()],
                    $position,
                    $category->displayLabel,
                ));
            }
        }
    }
}
