<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\ShareEnrichment;
use App\Repository\LinkCardRepository;
use App\Repository\ShareEnrichmentRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Content\LinkCard\Model\EntryCardsModel;
use App\Service\Content\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Social\Share\Model\ShareFetchPlanModel;
use App\Service\Library\Search\EntryIndexer;
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
        $plansByRowId = self::withinBudget($queued, $storedCards, $budget);
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
        return (float) $this->clock->now()->format('U.u') - (float) $claimedAt->format('U.u');
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
     * The oldest rows whose uncarded links fit the budget, by row id. The first row always runs, so no entry is left
     * half-carded; a posted form is read too only while the budget has room for it.
     *
     * @param list<ShareEnrichment>             $queued
     * @param array<int, list<LinkCardContent>> $storedCards by entry id
     *
     * @return array<int, ShareFetchPlanModel>
     */
    private static function withinBudget(array $queued, array $storedCards, LinkCardBudgetModel $budget): array
    {
        $remaining = $budget->fetches;
        $plansByRowId = [];
        foreach ($queued as $row) {
            $plan = self::planOf($row->getEntry(), $storedCards[$row->getEntry()->requireId()] ?? []);
            if ($plansByRowId !== [] && \count($plan->urls) > $remaining) {
                break;
            }
            $plan = $plan->fetches() > $remaining ? $plan->withoutChecks() : $plan;
            $plansByRowId[$row->requireId()] = $plan;
            $remaining -= $plan->fetches();
        }

        return $plansByRowId;
    }

    /** @param list<LinkCardContent> $storedCards */
    private static function planOf(Entry $entry, array $storedCards): ShareFetchPlanModel
    {
        return ShareTargets::planOf(
            $entry,
            array_map(static fn (LinkCardContent $card): string => $card->url, $storedCards),
        );
    }
}
