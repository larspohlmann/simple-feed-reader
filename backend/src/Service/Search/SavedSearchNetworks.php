<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearchNetworkInclusion;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SavedSearchNetworkInclusionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SavedSearchNetworks
{
    public function __construct(
        private SavedSearchNetworkInclusionRepository $inclusions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function include(User $user, SocialNetwork $network): void
    {
        if (null !== $this->inclusions->findOneFor($user->requireId(), $network)) {
            return;
        }
        $this->entityManager->persist(new SavedSearchNetworkInclusion($user, $network));
        $this->entityManager->flush();
    }

    public function exclude(User $user, SocialNetwork $network): void
    {
        $inclusion = $this->inclusions->findOneFor($user->requireId(), $network);
        if (null === $inclusion) {
            return;
        }
        $this->entityManager->remove($inclusion);
        $this->entityManager->flush();
    }
}
