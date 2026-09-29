<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\Entry;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Repository\EntryRepository;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Every ending of a run goes through here: cut the ranked list to the picks limit, drop picks whose entry was pruned
 * mid-run, and write the rest as RecommendationItems at dense positions before completing the run.
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
    public function finalize(RecommendationRun $run, array $ranked): RecommendationRunReportModel
    {
        $picks = \array_slice($ranked, 0, $this->settingsResolver->forUser($run->getUser())->poolLimits->picksLimit);
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

        return RecommendationRunReportModel::fromRun($run);
    }
}
