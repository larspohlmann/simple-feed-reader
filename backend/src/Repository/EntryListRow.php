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
    /** @var list<string> the feed-declared category labels, in declared order */
    public array $categories;
    /** @var list<array{id: int, slug: string, term: string}> owned saved searches this entry belongs to, sidebar order */
    public array $savedSearches;

    public function __construct(
        public Entry $entry,
        EntryListRowSubscription $subscription,
        public bool $isHidden,
        public bool $isFavorite,
        public bool $isKept,
        EntryListRowViewState $viewState,
        /**
         * The subscription's mark-all-read watermark, already selected by the
         * row projection. `isHidden` above has it folded in; it is carried
         * separately only so a row materialised from this projection can record
         * *when* the sweep hid the entry.
         */
        public ?\DateTimeImmutable $markedReadUntil,
        /** @var list<self> */
        public array $duplicates = [],
        EntryListRowEnrichment $enrichment = new EntryListRowEnrichment(),
    ) {
        $this->subscriptionId = $subscription->id;
        $this->subscriptionTitle = $subscription->title;
        $this->isViewed = $viewState->isViewed;
        $this->viewedAt = $viewState->viewedAt;
        $this->categories = $enrichment->categories;
        $this->savedSearches = $enrichment->savedSearches;
    }

    /**
     * @param list<self> $duplicates the in-scope copies this row collapsed
     */
    public function withDuplicates(array $duplicates): self
    {
        return $this->copyWith($duplicates, $this->categories, $this->savedSearches);
    }

    /** @param list<string> $categories */
    public function withCategories(array $categories): self
    {
        return $this->copyWith($this->duplicates, $categories, $this->savedSearches);
    }

    /** @param list<array{id: int, slug: string, term: string}> $savedSearches */
    public function withSavedSearches(array $savedSearches): self
    {
        return $this->copyWith($this->duplicates, $this->categories, $savedSearches);
    }

    /**
     * @param list<self>                                       $duplicates
     * @param list<string>                                     $categories
     * @param list<array{id: int, slug: string, term: string}> $savedSearches
     */
    private function copyWith(array $duplicates, array $categories, array $savedSearches): self
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
            new EntryListRowEnrichment($categories, $savedSearches),
        );
    }
}
