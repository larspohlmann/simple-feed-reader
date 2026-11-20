<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Repository\TrendingEntryRepository;
use App\Service\Curation\Trending\Model\CleanedHeadlineModel;
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

    /**
     * @param list<StoredStoryModel>                                $stored
     * @param \Closure(StoryAssignmentModel, StoredStoryModel): bool $keepsItsFallback
     */
    public function titled(
        TrendingRun $run,
        StoryMatchModel $match,
        array $stored,
        \Closure $keepsItsFallback,
    ): StoryMatchModel {
        $storedById = array_column($stored, null, 'id');
        $due = array_filter(
            $match->assignments,
            static fn (StoryAssignmentModel $assignment): bool => $assignment->storyId === null
                || !$keepsItsFallback($assignment, $storedById[$assignment->storyId]),
        );
        $titles = $this->resolveTitles($run, $due, $storedById);
        $assignments = array_map(
            static fn (StoryAssignmentModel $assignment, int $index): StoryAssignmentModel
                => isset($titles[$index]) ? $assignment->titled($titles[$index]) : $assignment,
            $match->assignments,
            array_keys($match->assignments),
        );

        return $match->withAssignments($assignments);
    }

    /**
     * @param array<int, StoryAssignmentModel> $due        by their index in the match
     * @param array<int, StoredStoryModel>     $storedById
     *
     * @return array<int, FallbackTitleModel> by the assignment's index in the match
     */
    private function resolveTitles(TrendingRun $run, array $due, array $storedById): array
    {
        $linkTitles = $this->windowEntries->findLinkTitles(self::collectCardLinkIds($due));
        $titles = [];
        $pools = [];
        foreach ($due as $index => $assignment) {
            $cardTitle = self::pickCardTitle($run, $assignment->group, $linkTitles);
            if ($cardTitle !== null) {
                $titles[$index] = $cardTitle;
                continue;
            }
            $pools[$index] = new HeadlinePoolModel(
                self::filterCandidates($run, $assignment->group),
                $assignment->group->entryIds(),
                $assignment->storyId === null ? null : $storedById[$assignment->storyId]->fallbackEntryId,
            );
        }

        return $titles + $this->headlineTitles($run, $pools);
    }

    /**
     * @param array<int, HeadlinePoolModel> $pools
     *
     * @return array<int, FallbackTitleModel> under their pool's key
     */
    private function headlineTitles(TrendingRun $run, array $pools): array
    {
        $headlines = $this->windowEntries->findHeadlines(array_merge(...array_values(array_map(
            static fn (HeadlinePoolModel $pool): array => $pool->memberIds(),
            $pools,
        ))));
        $titles = [];
        foreach ($pools as $index => $pool) {
            $candidates = $this->buildCandidates($run, $pool, $headlines);
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
    private function buildCandidates(TrendingRun $run, HeadlinePoolModel $pool, array $headlines): array
    {
        $cleaned = [];
        foreach ($pool->members as $member) {
            $headline = $headlines[$member->entryId] ?? null;
            if ($headline === null) {
                continue;
            }
            $text = $this->markers->clean($headline->title, $headline->feedTitle, $member->language);
            if ($text->text !== '') {
                $cleaned[$member->entryId] = $text;
            }
        }
        if ($cleaned === []) {
            return [];
        }
        $scores = \count($cleaned) > 1
            ? CentroidScores::computeScores($run->headlines, $pool->storyEntryIds)
            : array_fill_keys(array_keys($cleaned), 0.0);

        return array_map(
            static fn (int $entryId, CleanedHeadlineModel $text): TitleCandidateModel => new TitleCandidateModel(
                $entryId,
                $text,
                $scores[$entryId],
                $run->readEffectiveTime($entryId),
            ),
            array_keys($cleaned),
            $cleaned,
        );
    }

    /**
     * The outlet members in the majority language, a tie going to the newest member's; every member without one.
     *
     * @return list<StoryMemberModel>
     */
    private static function filterCandidates(TrendingRun $run, TrendingStoryModel $group): array
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
    private static function pickCardTitle(
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
            $linkId = self::findCardLinkId($member);
            if ($linkId === null || !isset($linkTitles[$linkId])) {
                continue;
            }
            $postsByLink[$linkId][$member->urlHash ?? 'entry ' . $member->entryId] = true;
            $newestHolderByLink[$linkId] = max(
                $newestHolderByLink[$linkId] ?? [0, 0],
                [$run->readEffectiveTime($member->entryId), $member->entryId],
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

    /**
     * @param array<int, StoryAssignmentModel> $due
     *
     * @return list<int>
     */
    private static function collectCardLinkIds(array $due): array
    {
        $linkIds = [];
        foreach ($due as $assignment) {
            if ($assignment->group->outletMembers() !== []) {
                continue;
            }
            foreach ($assignment->group->members as $member) {
                $linkId = self::findCardLinkId($member);
                if ($linkId !== null) {
                    $linkIds[$linkId] = $linkId;
                }
            }
        }

        return array_values($linkIds);
    }

    private static function findCardLinkId(StoryMemberModel $member): ?int
    {
        return $member->articleSite === null ? null : $member->sharedLinkId;
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
                => [$run->readEffectiveTime($right->entryId), $right->entryId]
                    <=> [$run->readEffectiveTime($left->entryId), $left->entryId],
        );

        return $members;
    }
}
