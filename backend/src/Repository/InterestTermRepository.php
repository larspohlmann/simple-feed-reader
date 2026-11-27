<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InterestTerm;
use App\Enum\FeedLanguage;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InterestTerm> */
final class InterestTermRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InterestTerm::class);
    }

    public function deleteForUser(int $userId): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('IDENTITY(t.user) = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
        $this->detachManagedForUser($userId);
    }

    private function detachManagedForUser(int $userId): void
    {
        $entityManager = $this->getEntityManager();
        foreach ($entityManager->getUnitOfWork()->getIdentityMap()[InterestTerm::class] ?? [] as $term) {
            if ($term instanceof InterestTerm && $term->getUser()->requireId() === $userId) {
                $entityManager->detach($term);
            }
        }
    }

    /** @return list<InterestTerm> */
    public function findForUser(int $userId): array
    {
        /** @var list<InterestTerm> $terms */
        $terms = $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.user) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $terms;
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, InterestTermsModel> every requested id, an empty model for none
     */
    public function findTermsForUsers(array $userIds): array
    {
        $strengths = array_fill_keys($userIds, []);
        if ($userIds !== []) {
            foreach ($this->readStrengthRows($userIds) as $row) {
                $key = InterestTermsModel::languageKey($row['language']);
                $strengths[(int) $row['userId']][$key][$row['stem']] = (float) $row['weight'] * (float) $row['rarity'];
            }
        }

        return array_map(InterestTermsModel::fromStrengths(...), $strengths);
    }

    /**
     * @param list<int> $userIds
     *
     * @return list<array{
     *     userId: int|string, stem: string, language: ?FeedLanguage, weight: float, rarity: float
     * }>
     */
    private function readStrengthRows(array $userIds): array
    {
        /** @var list<array{userId: int|string, stem: string, language: ?FeedLanguage, weight: float, rarity: float}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.user) AS userId', 't.stem', 't.language', 't.weight', 't.rarity')
            ->andWhere('IDENTITY(t.user) IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
