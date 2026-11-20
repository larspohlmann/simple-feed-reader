<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\ShareEnrichment;
use App\Repository\LinkCardRepository;
use App\Repository\ShareEnrichmentRepository;
use App\Service\Content\LinkCard\Model\EntryCardsModel;
use App\Service\Content\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Content\LinkCard\Support\ReusableShareUrls;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Clock\Support\ElapsedSeconds;
use App\Service\Library\Search\EntryIndexer;
use App\Service\Social\Share\Model\ShareFetchPlanModel;
use App\Service\Social\Share\Support\ShareTargets;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the cards a queued share's targets still lack, oldest share first: one attempt each, the row marked done
 * even when a page fails.
 */
final readonly class ShareLinkCards
{
    public function __construct(
        private ShareTargetReader $targets,
        private EntryCardWriter $cardWriter,
        private ShareEnrichmentRepository $enrichments,
        private LinkCardRepository $linkCards,
        private SharedLinkWriter $linkWriter,
        private EntityManagerInterface $entityManager,
        private EntryIndexer $indexer,
        private NaiveUtcClock $clock,
    ) {
    }

    /**
     * @param list<ShareEnrichment> $queued link-card rows, oldest first
     */
    public function complete(array $queued, LinkCardBudgetModel $budget): int
    {
        $storedCards = $this->storedCards($queued);
        $plansByRowId = self::withinBudget($queued, $this->buildPlans($queued, $storedCards), $budget);
        $claimedAt = $this->clock->now();
        $claimed = $this->enrichments->claim(
            array_values(array_filter(
                $queued,
                static fn (ShareEnrichment $row): bool => isset($plansByRowId[$row->requireId()]),
            )),
            $claimedAt,
        );

        $cardsByRowId = [];
        foreach ($claimed as $index => $row) {
            $plan = $plansByRowId[$row->requireId()];
            if ($index > 0 && !$budget->fitsAfter($this->secondsSince($claimedAt), $plan->fetches())) {
                $this->enrichments->release(\array_slice($claimed, $index), $claimedAt);
                break;
            }
            $cardsByRowId[$row->requireId()] = $this->targets->cardsFor(
                $row->getEntry(),
                $storedCards[$row->getEntry()->requireId()] ?? [],
                $plan,
            );
        }
        return $this->store($claimed, $cardsByRowId, $claimedAt);
    }

    /**
     * Only rows the claim still holds are written: no other worker cards their entries meanwhile, and the released
     * rows this call never worked are no longer held.
     *
     * @param list<ShareEnrichment>       $claimed
     * @param array<int, EntryCardsModel> $cardsByRowId the rows this call worked
     */
    private function store(array $claimed, array $cardsByRowId, \DateTimeImmutable $claimedAt): int
    {
        $held = $this->enrichments->stillHeld($claimed, $claimedAt);
        if ($held === []) {
            return 0;
        }

        foreach ($held as $row) {
            $this->cardWriter->write($row->getEntry(), $cardsByRowId[$row->requireId()]);
        }
        $this->entityManager->flush();
        $this->enrichments->markDone($held, $claimedAt);
        $this->indexer->index(array_map(static fn (ShareEnrichment $row): Entry => $row->getEntry(), $held));

        return \count($held);
    }

    private function secondsSince(\DateTimeImmutable $claimedAt): float
    {
        return ElapsedSeconds::between($claimedAt, $this->clock->now());
    }

    /**
     * @param list<ShareEnrichment> $rows
     *
     * @return array<int, list<LinkCardContent>> by entry id
     */
    private function storedCards(array $rows): array
    {
        return $this->linkCards->contentsByEntryIds(array_map(
            static fn (ShareEnrichment $row): int => $row->getEntry()->requireId(),
            $rows,
        ));
    }

    /**
     * Every row's plan, by row id, with the stored shared links its reusable links need no read for: one lookup for
     * all the rows.
     *
     * @param list<ShareEnrichment>             $queued
     * @param array<int, list<LinkCardContent>> $storedCards by entry id
     *
     * @return array<int, ShareFetchPlanModel>
     */
    private function buildPlans(array $queued, array $storedCards): array
    {
        $plans = [];
        $reusableUrls = [];
        foreach ($queued as $row) {
            $entry = $row->getEntry();
            $plan = ShareTargets::buildPlan($entry, array_map(
                static fn (LinkCardContent $card): string => $card->url,
                $storedCards[$entry->requireId()] ?? [],
            ));
            $plans[$row->requireId()] = $plan;
            $reusableUrls[$row->requireId()] = ReusableShareUrls::filterUrls($entry, $plan);
        }
        $known = $this->linkWriter->knownFor(array_merge(...array_values($reusableUrls)));
        foreach ($plans as $rowId => $plan) {
            $plans[$rowId] = $plan->withKnown(array_intersect_key($known, array_flip($reusableUrls[$rowId])));
        }

        return $plans;
    }

    /**
     * The oldest rows whose unknown links fit the budget, by row id. The first row always runs, so no entry is left
     * half-carded; a posted form is read too only while the budget has room for it.
     *
     * @param list<ShareEnrichment>           $queued
     * @param array<int, ShareFetchPlanModel> $plans  by row id
     *
     * @return array<int, ShareFetchPlanModel>
     */
    private static function withinBudget(array $queued, array $plans, LinkCardBudgetModel $budget): array
    {
        $remaining = $budget->fetches;
        $plansByRowId = [];
        foreach ($queued as $row) {
            $plan = $plans[$row->requireId()];
            if ($plansByRowId !== [] && $plan->withoutChecks()->fetches() > $remaining) {
                break;
            }
            $plan = $plan->fetches() > $remaining ? $plan->withoutChecks() : $plan;
            $plansByRowId[$row->requireId()] = $plan;
            $remaining -= $plan->fetches();
        }

        return $plansByRowId;
    }
}
