<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

/**
 * One row of the entry list: the shared Entry plus the caller-specific view of
 * it. `isHidden` already has the subscription watermark folded in, so the
 * client never re-derives it.
 */
final readonly class EntryListRow
{
    public int $subscriptionId;
    public string $subscriptionTitle;
    public bool $isViewed;
    public ?\DateTimeImmutable $viewedAt;

    public function __construct(
        public Entry $entry,
        EntryListRowSubscription $subscription,
        public bool $isHidden,
        public bool $isFavorite,
        public bool $isKept,
        EntryListRowViewState $viewState,
        /**
         * The subscription's watermark, already folded into isHidden; carried so EntryStateResolver can record when
         * it hid the entry.
         */
        public ?\DateTimeImmutable $markedReadUntil,
        /** @var list<self> */
        public array $duplicates = [],
        public EntryListRowEnrichment $enrichment = new EntryListRowEnrichment(),
    ) {
        $this->subscriptionId = $subscription->id;
        $this->subscriptionTitle = $subscription->title;
        $this->isViewed = $viewState->isViewed;
        $this->viewedAt = $viewState->viewedAt;
    }

    /**
     * @param list<self> $rows
     *
     * @return list<int> the ids of these rows and of their duplicates, each once
     */
    public static function entryIdsOf(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ($row->members() as $member) {
                $id = $member->entry->requireId();
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** @return list<self> this row, then its duplicates */
    public function members(): array
    {
        return [$this, ...$this->duplicates];
    }

    /**
     * This row and each of its duplicates, put through the same change.
     *
     * @param callable(self): self $change
     */
    public function mapWithDuplicates(callable $change): self
    {
        return $change($this->withDuplicates(array_map(
            static fn (self $duplicate): self => $duplicate->mapWithDuplicates($change),
            $this->duplicates,
        )));
    }

    /**
     * @param list<self> $duplicates the in-scope copies this row collapsed
     */
    public function withDuplicates(array $duplicates): self
    {
        return $this->copyWith($duplicates, $this->enrichment);
    }

    public function withEnrichment(EntryListRowEnrichment $enrichment): self
    {
        return $this->copyWith($this->duplicates, $enrichment);
    }

    /**
     * @param list<self> $duplicates
     */
    private function copyWith(array $duplicates, EntryListRowEnrichment $enrichment): self
    {
        return new self(
            $this->entry,
            new EntryListRowSubscription($this->subscriptionId, $this->subscriptionTitle),
            $this->isHidden,
            $this->isFavorite,
            $this->isKept,
            new EntryListRowViewState($this->isViewed, $this->viewedAt),
            $this->markedReadUntil,
            $duplicates,
            $enrichment,
        );
    }
}
