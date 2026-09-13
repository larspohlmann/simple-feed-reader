<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Service\Category\NormalizedCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
final class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findOneByIdentity(string $canonicalKey, string $scheme): ?Category
    {
        return $this->findOneBy(['canonicalKey' => $canonicalKey, 'scheme' => $scheme]);
    }

    /**
     * @param list<NormalizedCategory> $normalizedCategories
     *
     * @return array<string, Category> keyed by NormalizedCategory::identity()
     */
    public function findExistingByIdentities(array $normalizedCategories): array
    {
        if ($normalizedCategories === []) {
            return [];
        }

        $byIdentity = [];
        foreach ($this->findByCanonicalKeys($normalizedCategories) as $candidate) {
            $byIdentity[$candidate->getCanonicalKey() . "\0" . $candidate->getScheme()] = $candidate;
        }

        $resolved = [];
        foreach ($normalizedCategories as $category) {
            if (isset($byIdentity[$category->identity()])) {
                $resolved[$category->identity()] = $byIdentity[$category->identity()];
            }
        }

        return $resolved;
    }

    /**
     * @param list<NormalizedCategory> $normalizedCategories
     *
     * @return list<Category>
     */
    private function findByCanonicalKeys(array $normalizedCategories): array
    {
        $keys = array_unique(array_map(
            static fn (NormalizedCategory $category): string => $category->canonicalKey,
            $normalizedCategories,
        ));

        /** @var list<Category> $rows */
        $rows = $this->createQueryBuilder('category')
            ->where('category.canonicalKey IN (:keys)')
            ->setParameter('keys', array_values($keys))
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
