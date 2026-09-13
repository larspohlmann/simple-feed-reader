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
 * Resolves the feed-declared categories of newly created entries to globally
 * shared Category rows, then writes the per-entry links. Write-once: called
 * only for entries the ingest just created, never on refresh of an existing
 * entry.
 */
final class EntryCategoryWriter
{
    /** @var array<string, Category> */
    private array $resolvedByIdentity = [];

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
        $normalizedByEntry = $this->normalizeEach($pairs);
        $this->resolveCategories($this->distinctCategories($normalizedByEntry));
        $this->writeLinks($pairs, $normalizedByEntry);
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
     */
    private function resolveCategories(array $distinct): void
    {
        $createdAny = false;
        foreach ($distinct as $identity => $category) {
            $createdAny = $this->resolveOne($identity, $category) || $createdAny;
        }

        if ($createdAny) {
            $this->entityManager->flush();
        }
    }

    /**
     * A concurrent refresh of another feed can insert the same identity between
     * the lookup and this flush; the unique index rejects the duplicate and the
     * feed's refresh retries next cycle.
     */
    private function resolveOne(string $identity, NormalizedCategory $category): bool
    {
        $existing = $this->categories->findOneByIdentity($category->canonicalKey, $category->scheme);
        if ($existing !== null) {
            $this->resolvedByIdentity[$identity] = $existing;

            return false;
        }

        $created = new Category($category->canonicalKey, $category->scheme);
        $this->entityManager->persist($created);
        $this->resolvedByIdentity[$identity] = $created;

        return true;
    }

    /**
     * @param list<array{0: Entry, 1: ParsedEntry}> $pairs
     * @param list<list<NormalizedCategory>>        $normalizedByEntry
     */
    private function writeLinks(array $pairs, array $normalizedByEntry): void
    {
        foreach ($pairs as $index => [$entry]) {
            foreach ($normalizedByEntry[$index] as $position => $category) {
                $this->entityManager->persist(new EntryCategory(
                    $entry,
                    $this->resolvedByIdentity[$category->identity()],
                    $position,
                    $category->displayLabel,
                ));
            }
        }
    }
}
