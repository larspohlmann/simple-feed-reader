<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Membership\Model\SweepBudgetModel;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Model\SavedSearchDefinitionModel;
use App\Service\Search\Model\SavedSearchOutcomeModel;
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

    public function save(User $user, SavedSearchDefinitionModel $definition): SavedSearchOutcomeModel
    {
        $existing = $this->savedSearches->findOneForUserByTerm(
            $user->requireId(),
            $definition->term,
            $definition->wholeWord,
            $definition->phrase,
        );
        if (null !== $existing) {
            return SavedSearchOutcomeModel::existing($existing);
        }

        return SavedSearchOutcomeModel::created($this->create($user, $definition));
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

    private function create(User $user, SavedSearchDefinitionModel $definition): SavedSearch
    {
        $savedSearch = new SavedSearch($user, $definition->term, $definition->wholeWord, $definition->phrase);
        $this->entityManager->persist($savedSearch);
        $this->entityManager->flush();
        $this->slug->assignTo($savedSearch);
        $this->entityManager->flush();
        $this->sweep->sweepOne($savedSearch, SweepBudgetModel::seconds(self::CREATE_SWEEP_BUDGET_SECONDS));

        return $savedSearch;
    }
}
