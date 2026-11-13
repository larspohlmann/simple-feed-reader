<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Entity\NetworkExclusion;
use App\Entity\User;
use App\Enum\NetworkExclusionSurface;
use App\Enum\SocialNetwork;
use App\Repository\NetworkExclusionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ForYouNetworks
{
    public function __construct(
        private NetworkExclusionRepository $exclusions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function exclude(User $user, SocialNetwork $network): void
    {
        if (null !== $this->exclusionOf($user, $network)) {
            return;
        }
        $this->entityManager->persist(new NetworkExclusion($user, $network, NetworkExclusionSurface::ForYou));
        $this->entityManager->flush();
    }

    public function include(User $user, SocialNetwork $network): void
    {
        $exclusion = $this->exclusionOf($user, $network);
        if (null === $exclusion) {
            return;
        }
        $this->entityManager->remove($exclusion);
        $this->entityManager->flush();
    }

    private function exclusionOf(User $user, SocialNetwork $network): ?NetworkExclusion
    {
        return $this->exclusions->findOneFor($user->requireId(), $network, NetworkExclusionSurface::ForYou);
    }
}
