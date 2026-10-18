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
use App\Service\Social\Support\OldestFirstUndatedLast;
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
        $stored = $this->storedByGuid($feed, $page->selfReplies);
        $byRoot = self::unstoredByRoot($page->selfReplies, $stored);
        if ($byRoot === []) {
            return new TimelinePlanModel($page->parsed, TimelineThreadsModel::none());
        }
        $placements = $this->placementsOf($page, $byRoot, $stored);
        $placed = [];
        $unplaced = [];
        foreach ($placements as $rootGuid => $placement) {
            $placement->place(self::oldestFirst($byRoot[$rootGuid]));
            array_push($placed, ...$placement->placed());
            array_push($unplaced, ...$placement->unplaced());
        }

        return new TimelinePlanModel(
            $page->parsed->withEntries([
                ...$page->parsed->entries,
                ...self::orphansOf($byRoot, $placements),
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
            if ($root === null) {
                continue;
            }
            $this->folder->foldAt($root, $reply->position, $source->selfReplyOf($reply->post));
        }
    }

    /**
     * The entries already stored for the replies and their roots.
     *
     * @param list<TimelineSelfReplyModel> $replies
     *
     * @return array<string, Entry> by guid
     */
    private function storedByGuid(Feed $feed, array $replies): array
    {
        $guids = [];
        foreach ($replies as $reply) {
            array_push($guids, $reply->entry->guid, $reply->rootGuid);
        }
        $stored = [];
        foreach ($this->entries->findByFeedIndexedByGuidHash($feed, self::hashesOf($guids)) as $entry) {
            $stored[$entry->getGuid()] = $entry;
        }

        return $stored;
    }

    /**
     * @param list<TimelineSelfReplyModel> $replies
     * @param array<string, Entry>         $stored
     *
     * @return array<string, list<TimelineSelfReplyModel>> the replies not stored yet, by root guid
     */
    private static function unstoredByRoot(array $replies, array $stored): array
    {
        $byRoot = [];
        foreach ($replies as $reply) {
            if (!isset($stored[$reply->entry->guid])) {
                $byRoot[$reply->rootGuid][] = $reply;
            }
        }

        return $byRoot;
    }

    /**
     * Keyed by root guid: a stored root with its thread so far, else a root on the page; any other root is no key.
     *
     * @param array<string, list<TimelineSelfReplyModel>> $byRoot
     * @param array<string, Entry>                        $stored
     *
     * @return array<string, SelfThreadPlacement>
     */
    private function placementsOf(TimelinePageModel $page, array $byRoot, array $stored): array
    {
        $storedRoots = array_intersect_key($stored, $byRoot);
        $threadPositions = $this->threadPosts->positionsByEntryIds(array_values(array_map(
            static fn (Entry $root): int => $root->requireId(),
            $storedRoots,
        )));
        $placements = [];
        foreach ($storedRoots as $rootGuid => $root) {
            $thread = $threadPositions[$root->requireId()] ?? [];
            $placements[$rootGuid] = new SelfThreadPlacement($rootGuid, [(string) $root->getUrl() => 0] + $thread);
        }
        foreach ($page->parsed->entries as $entry) {
            if (!isset($placements[$entry->guid]) && isset($byRoot[$entry->guid])) {
                $placements[$entry->guid] = new SelfThreadPlacement($entry->guid, [(string) $entry->url => 0]);
            }
        }

        return $placements;
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
    private static function oldestFirst(array $replies): array
    {
        usort(
            $replies,
            static fn (TimelineSelfReplyModel $left, TimelineSelfReplyModel $right): int
                => OldestFirstUndatedLast::compare($left->entry->publishedAt, $right->entry->publishedAt),
        );

        return $replies;
    }

    /**
     * @param array<string, list<TimelineSelfReplyModel>> $byRoot
     * @param array<string, SelfThreadPlacement>          $placements
     *
     * @return list<ParsedEntryModel>
     */
    private static function orphansOf(array $byRoot, array $placements): array
    {
        $orphans = [];
        foreach (array_diff_key($byRoot, $placements) as $replies) {
            foreach ($replies as $reply) {
                $orphans[] = $reply->entry;
            }
        }

        return $orphans;
    }

    /**
     * @param array<string> $guids
     *
     * @return list<string>
     */
    private static function hashesOf(array $guids): array
    {
        return array_values(array_unique(array_map(
            static fn (string $guid): string => EntryGuid::hashOf($guid),
            $guids,
        )));
    }
}
