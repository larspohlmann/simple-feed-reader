<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SavedSearchEditor
{
    private const int CREATE_SWEEP_BUDGET_SECONDS = 8;

    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchMembershipSweep $sweep,
        private SavedSearchSlug $slug,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(User $user, SavedSearchDefinition $definition): SavedSearchOutcome
    {
        $existing = $this->savedSearches->findOneForUserByTerm(
            $user->requireId(),
            $definition->term,
            $definition->wholeWord,
            $definition->phrase,
        );
        if (null !== $existing) {
            return SavedSearchOutcome::existing($existing);
        }

        return SavedSearchOutcome::created($this->create($user, $definition));
    }

    public function changeDigestInclusion(SavedSearch $savedSearch, bool $includeInDigest): void
    {
        $savedSearch->setIncludeInDigest($includeInDigest);
        $this->entityManager->flush();
    }

    public function delete(SavedSearch $savedSearch): void
    {
        $this->entityManager->remove($savedSearch);
        $this->entityManager->flush();
    }

    private function create(User $user, SavedSearchDefinition $definition): SavedSearch
    {
        $savedSearch = new SavedSearch($user, $definition->term, $definition->wholeWord, $definition->phrase);
        $this->entityManager->persist($savedSearch);
        $this->entityManager->flush();
        $this->slug->assignTo($savedSearch);
        $this->entityManager->flush();
        $this->sweep->sweepOne($savedSearch, SweepBudget::seconds(self::CREATE_SWEEP_BUDGET_SECONDS));

        return $savedSearch;
    }
}
