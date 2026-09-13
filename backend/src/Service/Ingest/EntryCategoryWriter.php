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
        $resolvedByIdentity = [];
        $normalizedByEntry = $this->normalizeEach($pairs);
        $this->resolveCategories($this->distinctCategories($normalizedByEntry), $resolvedByIdentity);
        $this->writeLinks($pairs, $normalizedByEntry, $resolvedByIdentity);
    }

    /**
     * @param list<array{0: Entry, 1: ParsedEntry}> $pairs
     *
     * @return list<list<NormalizedCategory>>
     */
    private function normalizeEach(array $pairs): array
    {
        return array_map(
            fn (array $pair): array => $this->normalizer->normalize($pair[1]->categories),
            $pairs,
        );
    }

    /**
     * @param list<list<NormalizedCategory>> $normalizedByEntry
     *
     * @return array<string, NormalizedCategory>
     */
    private function distinctCategories(array $normalizedByEntry): array
    {
        $distinct = [];
        foreach ($normalizedByEntry as $categories) {
            foreach ($categories as $category) {
                $distinct[$category->identity()] = $category;
            }
        }

        return $distinct;
    }

    /**
     * @param array<string, NormalizedCategory> $distinct
     * @param array<string, Category>           $resolvedByIdentity
     */
    private function resolveCategories(array $distinct, array &$resolvedByIdentity): void
    {
        $createdAny = false;
        foreach ($distinct as $identity => $category) {
            $createdAny = $this->resolveOne($identity, $category, $resolvedByIdentity) || $createdAny;
        }

        if ($createdAny) {
            $this->entityManager->flush();
        }
    }

    /**
     * A concurrent refresh of another feed can insert the same identity between
     * the lookup and this flush; the unique index rejects the duplicate and the
     * feed's refresh retries next cycle.
     *
     * @param array<string, Category> $resolvedByIdentity
     */
    private function resolveOne(string $identity, NormalizedCategory $category, array &$resolvedByIdentity): bool
    {
        $existing = $this->categories->findOneByIdentity($category->canonicalKey, $category->scheme);
        if ($existing !== null) {
            $resolvedByIdentity[$identity] = $existing;

            return false;
        }

        $created = new Category($category->canonicalKey, $category->scheme);
        $this->entityManager->persist($created);
        $resolvedByIdentity[$identity] = $created;

        return true;
    }

    /**
     * @param list<array{0: Entry, 1: ParsedEntry}> $pairs
     * @param list<list<NormalizedCategory>>        $normalizedByEntry
     * @param array<string, Category>               $resolvedByIdentity
     */
    private function writeLinks(array $pairs, array $normalizedByEntry, array $resolvedByIdentity): void
    {
        foreach ($pairs as $index => [$entry]) {
            foreach ($normalizedByEntry[$index] as $position => $category) {
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
