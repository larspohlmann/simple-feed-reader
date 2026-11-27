<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTerm;
use App\Entity\InterestTermSet;
use App\Entity\User;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\Model\TermStemModel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class InterestTermStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InterestTermSetRepository $termSets,
        private InterestTermRepository $storedTerms,
        private StreamCeiling $ceiling,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<TermStemModel> $stems */
    public function replaceTerms(User $user, \DateTimeImmutable $profileGeneratedAt, array $stems): InterestTermSet
    {
        return $this->entityManager->wrapInTransaction(function () use (
            $user,
            $profileGeneratedAt,
            $stems,
        ): InterestTermSet {
            $this->storedTerms->deleteForUser($user->requireId());
            foreach ($stems as $stem) {
                $this->entityManager->persist(new InterestTerm($user, $stem->stem, $stem->language, $stem->weight));
            }
            $set = $this->termSets->findForUser($user);
            if ($set === null) {
                $set = new InterestTermSet(
                    $user,
                    $profileGeneratedAt,
                    $this->clock->now(),
                    $this->ceiling->readSettledCeiling(),
                );
                $this->entityManager->persist($set);
            } else {
                $set->replaceTerms($profileGeneratedAt, $this->clock->now());
            }
            $this->entityManager->flush();

            return $set;
        });
    }
}
