<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\KnownSocialCollection;
use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Service\Social\Model\PrivateFeedModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KnownSocialCollection>
 */
final class KnownSocialCollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KnownSocialCollection::class);
    }

    /** @return array<string, true> by kind:remoteId (PrivateFeedModel::keyOf) */
    public function keysOf(SocialAccount $account): array
    {
        $keys = [];
        foreach ($this->findBy(['socialAccount' => $account]) as $known) {
            $keys[PrivateFeedModel::keyOf($known->getKind(), $known->getRemoteId())] = true;
        }

        return $keys;
    }

    public function remember(SocialAccount $account, SocialFeedKind $kind, string $remoteId): void
    {
        if (null !== $this->findOneBy(['socialAccount' => $account, 'kind' => $kind, 'remoteId' => $remoteId])) {
            return;
        }
        $this->getEntityManager()->persist(new KnownSocialCollection($account, $kind, $remoteId));
    }

    /** @param list<PrivateFeedModel> $collections */
    public function forget(SocialAccount $account, array $collections): void
    {
        if ([] === $collections) {
            return;
        }
        $qb = $this->createQueryBuilder('known')
            ->delete()
            ->andWhere('known.socialAccount = :account')->setParameter('account', $account);
        $matches = $qb->expr()->orX();
        foreach ($collections as $index => $collection) {
            $matches->add(sprintf('known.kind = :kind%1$d AND known.remoteId = :remoteId%1$d', $index));
            $qb->setParameter('kind' . $index, $collection->kind)
                ->setParameter('remoteId' . $index, $collection->remoteId);
        }
        $qb->andWhere($matches)->getQuery()->execute();
    }
}
