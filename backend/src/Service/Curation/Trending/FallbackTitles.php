<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Repository\TrendingEntryRepository;
use App\Service\Curation\Trending\Model\FallbackTitleModel;
use App\Service\Curation\Trending\Model\HeadlinePoolModel;
use App\Service\Curation\Trending\Model\MemberHeadlineModel;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\TitleCandidateModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Curation\Trending\Support\CentroidScores;
use App\Service\Curation\Trending\Support\MostShared;

/**
 * A story takes its representative outlet headline in its majority language. Without an outlet article it takes the
 * title of its most shared card, and without a titled card its representative member's headline.
 */
final readonly class FallbackTitles
{
    public function __construct(
        private TrendingEntryRepository $windowEntries,
        private HeadlineMarkers $markers,
        private FallbackChoice $choice,
    ) {
    }

    /** @param list<StoredStoryModel> $stored */
    public function titledWhereMissing(TrendingRun $run, StoryMatchModel $match, array $stored): StoryMatchModel
    {
        $titled = array_flip(array_map(
            static fn (StoredStoryModel $story): int => $story->id,
            array_filter($stored, static fn (StoredStoryModel $story): bool => $story->titled),
        ));

        return $this->titledAmong($run, $match, $stored, array_filter(
            $match->assignments,
            static fn (StoryAssignmentModel $assignment): bool
                => $assignment->storyId === null || !isset($titled[$assignment->storyId]),
        ));
    }

    /**
     * An unchanged story keeps its fallback unscored: scoring every story made the week build ~16 % slower.
     *
     * @param list<StoredStoryModel> $stored
     */
    public function titledAll(TrendingRun $run, StoryMatchModel $match, array $stored): StoryMatchModel
    {
        $storedById = [];
        foreach ($stored as $story) {
            $storedById[$story->id] = $story;
        }

        return $this->titledAmong($run, $match, $stored, array_filter(
            $match->assignments,
            static fn (StoryAssignmentModel $assignment): bool => $assignment->storyId === null
                || !self::keepsItsFallback($assignment, $storedById[$assignment->storyId]),
        ));
    }

    /**
     * @param list<StoredStoryModel>           $stored
     * @param array<int, StoryAssignmentModel> $due    by their index in the match
     */
    private function titledAmong(TrendingRun $run, StoryMatchModel $match, array $stored, array $due): StoryMatchModel
    {
        $linkTitles = $this->windowEntries->linkTitlesOf(self::cardLinkIdsOf($due));
        $fallbackIds = self::fallbackIdsOf($stored);
        $titles = [];
        $pools = [];
        foreach ($due as $index => $assignment) {
            $cardTitle = self::cardTitleOf($run, $assignment->group, $linkTitles);
            if ($cardTitle !== null) {
                $titles[$index] = $cardTitle;
                continue;
            }
            $pools[$index] = new HeadlinePoolModel(
                self::candidatesOf($run, $assignment->group),
                $assignment->group->entryIds(),
                $assignment->storyId === null ? null : $fallbackIds[$assignment->storyId] ?? null,
            );
        }
        $titles += $this->headlineTitles($run, $pools);
        $assignments = array_map(
            static fn (StoryAssignmentModel $assignment, int $index): StoryAssignmentModel
                => isset($titles[$index]) ? $assignment->titled($titles[$index]) : $assignment,
            $match->assignments,
            array_keys($match->assignments),
        );

        return $match->withAssignments($assignments);
    }

    /**
     * @param array<int, HeadlinePoolModel> $pools
     *
     * @return array<int, FallbackTitleModel> under their pool's key
     */
    private function headlineTitles(TrendingRun $run, array $pools): array
    {
        $headlines = $this->windowEntries->headlinesOf(array_merge(...array_values(array_map(
            static fn (HeadlinePoolModel $pool): array => $pool->memberIds(),
            $pools,
        ))));
        $titles = [];
        foreach ($pools as $index => $pool) {
            $candidates = $this->candidatesIn($run, $pool, $headlines);
            if ($candidates !== []) {
                $chosen = $this->choice->choose($candidates, $pool->currentEntryId);
                $titles[$index] = new FallbackTitleModel($chosen->entryId, $chosen->headline->text);
            }
        }

        return $titles;
    }

    /**
     * @param array<int, MemberHeadlineModel> $headlines by entry id
     *
     * @return list<TitleCandidateModel>
     */
    private function candidatesIn(TrendingRun $run, HeadlinePoolModel $pool, array $headlines): array
    {
        $titled = array_values(array_filter(
            $pool->members,
            static fn (StoryMemberModel $member): bool => isset($headlines[$member->entryId]),
        ));
        if ($titled === []) {
            return [];
        }
        $scores = CentroidScores::of($run->headlines, $pool->storyEntryIds);

        return array_map(
            fn (StoryMemberModel $member): TitleCandidateModel => new TitleCandidateModel(
                $member->entryId,
                $this->markers->clean(
                    $headlines[$member->entryId]->title,
                    $headlines[$member->entryId]->feedTitle,
                    $member->language,
                ),
                $scores[$member->entryId],
                $run->effectiveTimeOf($member->entryId),
            ),
            $titled,
        );
    }

    /**
     * The outlet members in the majority language, a tie going to the newest member's; every member without one.
     *
     * @return list<StoryMemberModel>
     */
    private static function candidatesOf(TrendingRun $run, TrendingStoryModel $group): array
    {
        $outlets = $group->outletMembers();
        if ($outlets === []) {
            return $group->members;
        }
        $counts = [];
        foreach ($outlets as $member) {
            if ($member->language !== null) {
                $counts[$member->language->value] = ($counts[$member->language->value] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return $outlets;
        }
        $most = max($counts);
        $majority = array_find(
            self::newestFirst($run, $outlets),
            static fn (StoryMemberModel $member): bool
                => $member->language !== null && ($counts[$member->language->value] ?? 0) === $most,
        )?->language;

        return array_values(array_filter(
            $outlets,
            static fn (StoryMemberModel $member): bool => $member->language === $majority,
        ));
    }

    /** @param array<int, string> $linkTitles by link id */
    private static function cardTitleOf(
        TrendingRun $run,
        TrendingStoryModel $group,
        array $linkTitles,
    ): ?FallbackTitleModel {
        if ($group->outletMembers() !== []) {
            return null;
        }
        $postsByLink = [];
        $newestHolderByLink = [];
        foreach ($group->members as $member) {
            $linkId = self::cardLinkOf($member);
            if ($linkId === null || !isset($linkTitles[$linkId])) {
                continue;
            }
            $postsByLink[$linkId][$member->urlHash ?? 'entry ' . $member->entryId] = true;
            $newestHolderByLink[$linkId] = max(
                $newestHolderByLink[$linkId] ?? [0, 0],
                [$run->effectiveTimeOf($member->entryId), $member->entryId],
            );
        }
        if ($newestHolderByLink === []) {
            return null;
        }
        arsort($newestHolderByLink);
        $linkId = MostShared::pick(
            array_keys($newestHolderByLink),
            static fn (int $linkId): int => \count($postsByLink[$linkId]),
        );

        return new FallbackTitleModel(null, $linkTitles[$linkId]);
    }

    private static function keepsItsFallback(StoryAssignmentModel $assignment, StoredStoryModel $story): bool
    {
        if (!$story->titled || $story->fallbackEntryId === null) {
            return false;
        }
        $entryIds = $assignment->group->entryIds();
        $storedEntryIds = $story->entryIds;
        sort($entryIds);
        sort($storedEntryIds);

        return $entryIds === $storedEntryIds && \in_array($story->fallbackEntryId, $entryIds, true);
    }

    /**
     * @param array<int, StoryAssignmentModel> $due
     *
     * @return list<int>
     */
    private static function cardLinkIdsOf(array $due): array
    {
        $linkIds = [];
        foreach ($due as $assignment) {
            if ($assignment->group->outletMembers() !== []) {
                continue;
            }
            foreach ($assignment->group->members as $member) {
                $linkId = self::cardLinkOf($member);
                if ($linkId !== null) {
                    $linkIds[$linkId] = $linkId;
                }
            }
        }

        return array_values($linkIds);
    }

    private static function cardLinkOf(StoryMemberModel $member): ?int
    {
        return $member->articleSite === null ? null : $member->sharedLinkId;
    }

    /**
     * @param list<StoredStoryModel> $stored
     *
     * @return array<int, int> by story id
     */
    private static function fallbackIdsOf(array $stored): array
    {
        $fallbackIds = [];
        foreach ($stored as $story) {
            if ($story->fallbackEntryId !== null) {
                $fallbackIds[$story->id] = $story->fallbackEntryId;
            }
        }

        return $fallbackIds;
    }

    /**
     * @param list<StoryMemberModel> $members
     *
     * @return list<StoryMemberModel>
     */
    private static function newestFirst(TrendingRun $run, array $members): array
    {
        usort(
            $members,
            static fn (StoryMemberModel $left, StoryMemberModel $right): int
                => [$run->effectiveTimeOf($right->entryId), $right->entryId]
                    <=> [$run->effectiveTimeOf($left->entryId), $left->entryId],
        );

        return $members;
    }
}
