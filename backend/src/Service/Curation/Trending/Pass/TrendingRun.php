<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
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

    /** @var array<int, FeedLanguage> */
    private array $languages = [];

    /** @var array<int, true> the entries whose feed publishes articles */
    private array $outletEntries = [];

    /** @var array<int, string> */
    private array $urlHashes = [];

    /** @var array<int, int> entry id => effective date in Unix seconds */
    private array $effectiveTimes = [];

    /** @var array<string, list<int>> */
    private array $entriesByUrlHash = [];

    /** @var array<int, string> */
    private array $articleSites = [];

    /** @var array<int, int> entry id => the shared link of its first top-level card */
    private array $sharedLinkIds = [];

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
        if ($entry->language !== null) {
            $this->languages[$entry->id] = $entry->language;
        }
        if ($entry->links->articleUrl !== null) {
            $this->outletEntries[$entry->id] = true;
        }
        if ($entry->links->urlHash !== null) {
            $this->urlHashes[$entry->id] = $entry->links->urlHash;
        }
        foreach ($urlHashes as $urlHash) {
            $this->entriesByUrlHash[$urlHash][] = $entry->id;
        }
        if ($entry->links->card !== null) {
            $this->sharedLinkIds[$entry->id] = $entry->links->card->sharedLinkId;
        }
        $articleSite = $entry->links->articleSite();
        if ($articleSite !== null) {
            $this->articleSites[$entry->id] = $this->sites[$articleSite] ??= $articleSite;
        }
        $this->headlines->add(
            $entry,
            $entry->titleDerived
                ? HeadlineBody::bodyAfterTitle($entry->title, $entry->summary)
                : HeadlineBody::body($entry->summary),
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
        return new StoryMemberModel(
            $entryId,
            $this->feedOfEntry[$entryId],
            $this->articleSites[$entryId] ?? null,
            $this->sharedLinkIds[$entryId] ?? null,
            $this->languages[$entryId] ?? null,
            isset($this->outletEntries[$entryId]),
            $this->urlHashes[$entryId] ?? null,
        );
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
