<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Service\Curation\YourStream\Model\StreamStrengthsModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<YourStreamWord> */
final class YourStreamWordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, YourStreamWord::class);
    }

    public function deleteForUser(int $userId): void
    {
        $this->createQueryBuilder('w')
            ->delete()
            ->andWhere('IDENTITY(w.user) = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
        $this->detachManagedForUser($userId);
    }

    /** @return list<YourStreamWord> */
    public function findForUser(int $userId): array
    {
        /** @var list<YourStreamWord> $words */
        $words = $this->createQueryBuilder('w')
            ->andWhere('IDENTITY(w.user) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('w.weight', 'DESC')
            ->addOrderBy('w.stem', 'ASC')
            ->getQuery()
            ->getResult();

        return $words;
    }

    public function hasWordsForUser(int $userId): bool
    {
        $found = $this->createQueryBuilder('w')
            ->select('1')
            ->andWhere('IDENTITY(w.user) = :userId')
            ->setParameter('userId', $userId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $found;
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, StreamStrengthsModel> every requested id, an empty model for none
     */
    public function findStrengthsForUsers(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $strengths = array_fill_keys($userIds, []);
        foreach ($this->readStrengthRows($userIds) as $row) {
            $key = StreamStrengthsModel::languageKey($row['language']);
            $strengths[(int) $row['userId']][$key][$row['stem']] = (float) $row['weight'] * (float) $row['rarity'];
        }

        return array_map(StreamStrengthsModel::fromStrengths(...), $strengths);
    }

    private function detachManagedForUser(int $userId): void
    {
        $entityManager = $this->getEntityManager();
        foreach ($entityManager->getUnitOfWork()->getIdentityMap()[YourStreamWord::class] ?? [] as $word) {
            if ($word instanceof YourStreamWord && $word->getUser()->requireId() === $userId) {
                $entityManager->detach($word);
            }
        }
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
        $rows = $this->createQueryBuilder('w')
            ->select('IDENTITY(w.user) AS userId', 'w.stem', 'w.language', 'w.weight', 'w.rarity')
            ->andWhere('IDENTITY(w.user) IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
