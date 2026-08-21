<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Repository\EntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Every ending of a recommendation run funnels through here (#338 lifted it out
 * of RecommendationRunAdvancer): cut the ranked list to the reader's picks
 * limit, re-check that each surviving pick's entry still exists -- the candidate
 * pool can be pruned mid-run -- and write the survivors as RecommendationItems
 * at dense positions in pick order before marking the run completed.
 *
 * The cut lives here so a new ending cannot ship an over-long list by forgetting
 * to slice.
 */
final readonly class RecommendationRunFinalizer
{
    public function __construct(
        private EntryRepository $entries,
        private EntityManagerInterface $entityManager,
        private RecommendationSettingsResolver $settingsResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     */
    public function finalize(RecommendationRun $run, array $ranked): RecommendationRunReport
    {
        $picks = \array_slice($ranked, 0, $this->settingsResolver->forUser($run->getUser())->picksLimit);
        $existingIds = $this->entries->findExistingIds(array_map(
            static fn (array $pick): int => $pick['id'],
            $picks,
        ));

        $position = 0;
        foreach ($picks as $pick) {
            if (!\in_array($pick['id'], $existingIds, true)) {
                continue;
            }

            $position++;
            $entryReference = $this->entityManager->getReference(Entry::class, $pick['id'])
                ?? throw new \LogicException('Entry ' . $pick['id'] . ' was confirmed to exist a moment ago.');
            $this->entityManager->persist(
                new RecommendationItem($run, $entryReference, $position, $pick['reason'], $pick['score']),
            );
        }

        $run->complete($this->clock->now());
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }
}
