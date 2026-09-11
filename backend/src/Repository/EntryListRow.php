<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

/**
 * One row of the entry list: the shared Entry plus the caller-specific view of
 * it. `isHidden` already has the subscription watermark folded in, so the client
 * never re-derives it. `subscriptionId`/`subscriptionTitle` identify the source
 * for a cross-feed listing; they arrive bundled as an EntryListRowSubscription
 * so this constructor stays under PHPMD's parameter-count gate.
 */
final readonly class EntryListRow
{
    public int $subscriptionId;
    public string $subscriptionTitle;

    public function __construct(
        public Entry $entry,
        EntryListRowSubscription $subscription,
        public bool $isHidden,
        public bool $isFavorite,
        public bool $isKept,
        public bool $isViewed,
        /**
         * When the caller opened this entry in the reader, or null if never.
         * The "viewed" list orders by it (see EntryListSort::ViewedAt); every
         * other list ignores it. Non-null on every row the "viewed" view
         * returns, because that view filters on `es.isViewed = true` and
         * markViewed() stamps both together.
         */
        public ?\DateTimeImmutable $viewedAt,
        /**
         * The subscription's mark-all-read watermark, already selected by the
         * row projection. `isHidden` above has it folded in; it is carried
         * separately only so a row materialised from this projection can record
         * *when* the sweep hid the entry.
         */
        public ?\DateTimeImmutable $markedReadUntil,
        /** @var list<self> */
        public array $duplicates = [],
    ) {
        $this->subscriptionId = $subscription->id;
        $this->subscriptionTitle = $subscription->title;
    }

    /**
     * @param list<self> $duplicates the in-scope copies this row collapsed
     */
    public function withDuplicates(array $duplicates): self
    {
        return new self(
            $this->entry,
            new EntryListRowSubscription($this->subscriptionId, $this->subscriptionTitle),
            $this->isHidden,
            $this->isFavorite,
            $this->isKept,
            $this->isViewed,
            $this->viewedAt,
            $this->markedReadUntil,
            $duplicates,
        );
    }
}
