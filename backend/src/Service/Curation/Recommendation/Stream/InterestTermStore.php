<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTerm;
use App\Entity\InterestTermSet;
use App\Entity\User;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\Model\ExtractedTermsModel;
use App\Service\Library\Search\Membership\SettledCeiling;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class InterestTermStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InterestTermSetRepository $termSets,
        private InterestTermRepository $storedTerms,
        private SettledCeiling $ceiling,
        private ClockInterface $clock,
    ) {
    }

    public function replaceTerms(
        User $user,
        \DateTimeImmutable $profileGeneratedAt,
        ExtractedTermsModel $extracted,
    ): InterestTermSet {
        return $this->entityManager->wrapInTransaction(function () use (
            $user,
            $profileGeneratedAt,
            $extracted,
        ): InterestTermSet {
            $this->storedTerms->deleteForUser($user->requireId());
            foreach ($extracted->stems as $stem) {
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
            $set->recordPhrases($extracted->phrases);
            $this->entityManager->flush();

            return $set;
        });
    }
}
