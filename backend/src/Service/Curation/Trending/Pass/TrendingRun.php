<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Support\EntryText;

/** One rebuild of one window: who published each entry when, which URLs it carries, and the headline index. */
final class TrendingRun
{
    /** @var array<int, int> */
    private array $feedOfEntry = [];

    /** @var array<int, int> entry id => effective date in Unix seconds */
    private array $effectiveTimes = [];

    /** @var array<string, list<int>> */
    private array $entriesByUrlHash = [];

    public function __construct(public readonly TrendingWindow $window, public readonly HeadlineWindow $headlines)
    {
    }

    /** @param list<string> $urlHashes the entry's own and its shared article's, distinct */
    public function add(WindowEntryModel $entry, array $urlHashes): void
    {
        $this->feedOfEntry[$entry->id] = $entry->feedId;
        $this->effectiveTimes[$entry->id] = $entry->effectiveDate->getTimestamp();
        foreach ($urlHashes as $urlHash) {
            $this->entriesByUrlHash[$urlHash][] = $entry->id;
        }
        $this->headlines->add(
            $entry->id,
            $entry->feedId,
            $entry->title,
            $entry->titleDerived
                ? EntryText::bodyAfterTitle($entry->title, $entry->summary)
                : EntryText::body($entry->summary),
        );
    }

    /** @return list<int> */
    public function entryIds(): array
    {
        return array_keys($this->feedOfEntry);
    }

    public function entryCount(): int
    {
        return \count($this->feedOfEntry);
    }

    public function feedOf(int $entryId): int
    {
        return $this->feedOfEntry[$entryId];
    }

    public function effectiveTimeOf(int $entryId): int
    {
        return $this->effectiveTimes[$entryId];
    }

    public function sameFeed(int $oneEntryId, int $otherEntryId): bool
    {
        return $this->feedOfEntry[$oneEntryId] === $this->feedOfEntry[$otherEntryId];
    }

    public function gapSeconds(int $oneEntryId, int $otherEntryId): int
    {
        return abs($this->effectiveTimes[$oneEntryId] - $this->effectiveTimes[$otherEntryId]);
    }

    public function withinGap(int $oneEntryId, int $otherEntryId): bool
    {
        return $this->gapSeconds($oneEntryId, $otherEntryId) <= $this->window->maxLinkGapSeconds();
    }

    public function mayLink(int $oneEntryId, int $otherEntryId): bool
    {
        return !$this->sameFeed($oneEntryId, $otherEntryId) && $this->withinGap($oneEntryId, $otherEntryId);
    }

    /** @return list<list<int>> the entries of every URL more than one entry carries */
    public function entriesSharingAUrl(): array
    {
        return array_values(array_filter(
            $this->entriesByUrlHash,
            static fn (array $entryIds): bool => \count($entryIds) > 1,
        ));
    }
}
