<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryGuid;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Social\Model\PlacedSelfReplyModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Model\TimelinePlanModel;
use App\Service\Social\Model\TimelineSelfReplyModel;
use App\Service\Social\Model\TimelineThreadsModel;
use App\Service\Social\Pass\SelfThreadPlacement;
use App\Service\Social\ReplyThreadSource\ReplyThreadSourceInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** A timeline's self-replies: placed on their root's thread before the page is stored, folded into it after. */
final readonly class TimelineSelfThreads
{
    public function __construct(
        private EntryRepository $entries,
        private ThreadPostRepository $threadPosts,
        private SelfThreadFolder $folder,
        #[AutowireLocator('app.social_reply_thread_source', defaultIndexMethod: 'network')]
        private ContainerInterface $threads,
    ) {
    }

    public function plan(Feed $feed, TimelinePageModel $page): TimelinePlanModel
    {
        if ($page->selfReplies === []) {
            return new TimelinePlanModel($page->parsed, TimelineThreadsModel::none());
        }
        $unstored = $this->unstoredOf($feed, $page->selfReplies);
        $placements = $this->placementsOf($feed, $page, $unstored);
        $placed = [];
        $unplaced = [];
        foreach ($placements as $rootGuid => $placement) {
            $placement->place(self::repliesUnder($rootGuid, $unstored));
            array_push($placed, ...$placement->placed());
            array_push($unplaced, ...$placement->unplaced());
        }

        return new TimelinePlanModel(
            $page->parsed->withEntries([
                ...$page->parsed->entries,
                ...self::orphansOf($unstored, $placements),
                ...$unplaced,
            ]),
            new TimelineThreadsModel($placed),
        );
    }

    /** @param list<Entry> $createdEntries persisted, not yet flushed */
    public function fold(Feed $feed, TimelineThreadsModel $threads, array $createdEntries): void
    {
        if ($threads->placed === []) {
            return;
        }
        $roots = $this->rootsOf($feed, $threads, $createdEntries);
        $source = $this->sourceFor($feed);
        foreach ($threads->placed as $reply) {
            $root = $roots[$reply->rootGuid] ?? null;
            if ($root !== null) {
                $this->folder->foldAt($root, $reply->position, $source->selfReplyOf($reply->post));
            }
        }
    }

    /**
     * @param list<TimelineSelfReplyModel> $replies
     *
     * @return list<TimelineSelfReplyModel>
     */
    private function unstoredOf(Feed $feed, array $replies): array
    {
        $guids = array_map(static fn (TimelineSelfReplyModel $reply): string => $reply->entry->guid, $replies);
        $storedGuids = array_map(
            static fn (Entry $entry): string => $entry->getGuid(),
            $this->entries->findByFeedIndexedByGuidHash($feed, self::hashesOf($guids)),
        );

        return array_values(array_filter(
            $replies,
            static fn (TimelineSelfReplyModel $reply): bool => !\in_array($reply->entry->guid, $storedGuids, true),
        ));
    }

    /**
     * Keyed by root guid: a stored root with its thread so far, else a root on the page; any other root is no key.
     *
     * @param list<TimelineSelfReplyModel> $replies
     *
     * @return array<string, SelfThreadPlacement>
     */
    private function placementsOf(Feed $feed, TimelinePageModel $page, array $replies): array
    {
        $rootGuids = array_values(array_unique(array_map(
            static fn (TimelineSelfReplyModel $reply): string => $reply->rootGuid,
            $replies,
        )));
        $placements = [];
        foreach ($this->storedPositionsOf($feed, $rootGuids) as $rootGuid => $positions) {
            $placements[$rootGuid] = new SelfThreadPlacement($rootGuid, $positions);
        }
        foreach ($page->parsed->entries as $entry) {
            if (!isset($placements[$entry->guid]) && \in_array($entry->guid, $rootGuids, true)) {
                $placements[$entry->guid] = new SelfThreadPlacement($entry->guid, [(string) $entry->url => 0]);
            }
        }

        return $placements;
    }

    /**
     * @param list<string> $rootGuids
     *
     * @return array<string, array<string, int>> by root guid: url => position, the root at 0
     */
    private function storedPositionsOf(Feed $feed, array $rootGuids): array
    {
        $roots = $this->entries->findByFeedIndexedByGuidHash($feed, self::hashesOf($rootGuids));
        $threadPositions = $this->threadPosts->positionsByEntryIds(array_values(array_map(
            static fn (Entry $root): int => $root->requireId(),
            $roots,
        )));
        $positions = [];
        foreach ($roots as $root) {
            $thread = $threadPositions[$root->requireId()] ?? [];
            $positions[$root->getGuid()] = [(string) $root->getUrl() => 0] + $thread;
        }

        return $positions;
    }

    /**
     * @param list<Entry> $createdEntries
     *
     * @return array<string, Entry> by guid
     */
    private function rootsOf(Feed $feed, TimelineThreadsModel $threads, array $createdEntries): array
    {
        $roots = [];
        foreach ($createdEntries as $entry) {
            $roots[$entry->getGuid()] = $entry;
        }
        $storedGuids = array_diff(
            array_map(static fn (PlacedSelfReplyModel $reply): string => $reply->rootGuid, $threads->placed),
            array_keys($roots),
        );
        foreach ($this->entries->findByFeedIndexedByGuidHash($feed, self::hashesOf($storedGuids)) as $root) {
            $roots[$root->getGuid()] = $root;
        }

        return $roots;
    }

    private function sourceFor(Feed $feed): ReplyThreadSourceInterface
    {
        $source = $this->threads->get($feed->getNetwork()->value ?? '');
        \assert($source instanceof ReplyThreadSourceInterface);

        return $source;
    }

    /**
     * @param list<TimelineSelfReplyModel> $replies
     *
     * @return list<TimelineSelfReplyModel> oldest first, an undated one last
     */
    private static function repliesUnder(string $rootGuid, array $replies): array
    {
        $under = array_values(array_filter(
            $replies,
            static fn (TimelineSelfReplyModel $reply): bool => $reply->rootGuid === $rootGuid,
        ));
        usort(
            $under,
            static fn (TimelineSelfReplyModel $left, TimelineSelfReplyModel $right): int
                => [$left->entry->publishedAt === null, $left->entry->publishedAt]
                <=> [$right->entry->publishedAt === null, $right->entry->publishedAt],
        );

        return $under;
    }

    /**
     * @param list<TimelineSelfReplyModel>        $replies
     * @param array<string, SelfThreadPlacement> $placements
     *
     * @return list<ParsedEntryModel>
     */
    private static function orphansOf(array $replies, array $placements): array
    {
        return array_values(array_map(
            static fn (TimelineSelfReplyModel $reply): ParsedEntryModel => $reply->entry,
            array_filter(
                $replies,
                static fn (TimelineSelfReplyModel $reply): bool => !isset($placements[$reply->rootGuid]),
            ),
        ));
    }

    /**
     * @param array<string> $guids
     *
     * @return list<string>
     */
    private static function hashesOf(array $guids): array
    {
        return array_values(array_unique(array_map(
            static fn (string $guid): string => (new EntryGuid($guid))->getHash(),
            $guids,
        )));
    }
}
