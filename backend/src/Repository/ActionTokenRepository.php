<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActionToken;
use App\Entity\User;
use App\Enum\TokenPurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionToken>
 */
class ActionTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionToken::class);
    }

    /**
     * Looked up by the hash alone, which carries a unique index, so the purpose
     * acts purely as a filter and never widens the scan.
     */
    public function findOneByHashAndPurpose(string $tokenHash, TokenPurpose $purpose): ?ActionToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash, 'purpose' => $purpose]);
    }

    /** @return list<ActionToken> */
    public function findUnconsumedFor(User $user, TokenPurpose $purpose): array
    {
        /** @var list<ActionToken> $tokens */
        $tokens = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.purpose = :purpose')
            ->andWhere('t.consumedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->getQuery()
            ->getResult();

        return $tokens;
    }
}
