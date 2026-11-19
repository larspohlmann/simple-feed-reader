<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\SharedArticleModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Support\HeadlineBody;

/** One rebuild of one window: who published each entry when, which URLs it carries, and the headline index. */
final class TrendingRun
{
    /** @var array<int, int> */
    private array $feedOfEntry = [];

    /** @var array<int, StoryMemberModel> */
    private array $members = [];

    /** @var array<int, int> entry id => effective date in Unix seconds */
    private array $effectiveTimes = [];

    /** @var array<string, list<int>> */
    private array $entriesByUrlHash = [];

    /** @var array<string, string> one string per site, however many entries share it */
    private array $sites = [];

    public function __construct(
        public readonly TrendingWindow $window,
        public readonly \DateTimeImmutable $since,
        public readonly HeadlineWindow $headlines,
    ) {
    }

    /** @param list<string> $urlHashes the entry's own and its shared article's, distinct */
    public function add(WindowEntryModel $entry, array $urlHashes, ?SharedArticleModel $article): void
    {
        $this->feedOfEntry[$entry->id] = $entry->feedId;
        $this->effectiveTimes[$entry->id] = $entry->effectiveDate->getTimestamp();
        foreach ($urlHashes as $urlHash) {
            $this->entriesByUrlHash[$urlHash][] = $entry->id;
        }
        $articleSite = $entry->links->articleSite();
        $this->members[$entry->id] = new StoryMemberModel(
            $entry->id,
            $entry->feedId,
            $articleSite === null ? null : ($this->sites[$articleSite] ??= $articleSite),
            $entry->links->card?->sharedLinkId,
            $entry->language,
            $entry->links->articleUrl !== null,
            $entry->links->urlHash,
        );
        $this->headlines->add(
            $entry,
            $entry->titleDerived
                ? HeadlineBody::bodyAfterTitle($entry->title, $entry->summary, HeadlineBody::BODY_LENGTH)
                : HeadlineBody::body($entry->summary, HeadlineBody::BODY_LENGTH),
            $article,
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

    public function memberOf(int $entryId): StoryMemberModel
    {
        return $this->members[$entryId];
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
