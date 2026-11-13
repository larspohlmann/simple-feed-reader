<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\NetworkExclusion;
use App\Entity\User;
use App\Enum\NetworkExclusionSurface;
use App\Enum\SocialNetwork;
use App\Repository\NetworkExclusionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NetworkExclusions
{
    public function __construct(
        private NetworkExclusionRepository $exclusions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function exclude(User $user, SocialNetwork $network, NetworkExclusionSurface $surface): void
    {
        if (null !== $this->exclusions->findOneFor($user->requireId(), $network, $surface)) {
            return;
        }
        $this->entityManager->persist(new NetworkExclusion($user, $network, $surface));
        $this->entityManager->flush();
    }

    public function include(User $user, SocialNetwork $network, NetworkExclusionSurface $surface): void
    {
        $exclusion = $this->exclusions->findOneFor($user->requireId(), $network, $surface);
        if (null === $exclusion) {
            return;
        }
        $this->entityManager->remove($exclusion);
        $this->entityManager->flush();
    }
}
