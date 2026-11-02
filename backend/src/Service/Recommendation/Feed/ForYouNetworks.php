<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Entity\ForYouNetworkExclusion;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\ForYouNetworkExclusionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ForYouNetworks
{
    public function __construct(
        private ForYouNetworkExclusionRepository $exclusions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function exclude(User $user, SocialNetwork $network): void
    {
        if (null !== $this->exclusions->findOneFor($user->requireId(), $network)) {
            return;
        }
        $this->entityManager->persist(new ForYouNetworkExclusion($user, $network));
        $this->entityManager->flush();
    }

    public function include(User $user, SocialNetwork $network): void
    {
        $exclusion = $this->exclusions->findOneFor($user->requireId(), $network);
        if (null === $exclusion) {
            return;
        }
        $this->entityManager->remove($exclusion);
        $this->entityManager->flush();
    }
}
