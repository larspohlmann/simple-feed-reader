<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SavedSearchNetworkInclusionRepository;
use App\Repository\SavedSearchRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Service\Backup\Factory\BackupPartWalkFactory;
use App\Service\Backup\Model\BackupPartModel;
use App\Service\Backup\Model\BackupProvenanceModel;
use App\Service\Backup\Model\FoundationSnapshotModel;
use App\Service\Backup\Pass\BackupPartWalk;
use App\Service\Social\Model\ExportedSubscriptionModel;
use App\Service\Social\Support\ExportedFeed;
use App\Service\Tag\Pass\TagTree;
use Psr\Clock\ClockInterface;

/**
 * One account as gzipped backup parts: every entry part first, then the foundation, whose header needs their count
 * and totals. Its lines are captured before the entry walk clear()s the entity manager, so both name the same feeds.
 */
final readonly class AccountBackupExporter
{
    public function __construct(
        private UserRepository $users,
        private TagRepository $tags,
        private SavedSearchRepository $savedSearches,
        private SavedSearchNetworkInclusionRepository $includedNetworks,
        private SubscriptionRepository $subscriptions,
        private BackupLines $lines,
        private BackupPartWalkFactory $walkFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return \Generator<int, BackupPartModel>
     */
    public function parts(User $user, ?string $sourceUrl): \Generator
    {
        $userId = $user->requireId();
        $provenance = $this->provenanceOf($user, $sourceUrl);
        $foundation = $this->foundationSnapshot($userId);
        $walk = $this->walkFactory->create($provenance, $userId);

        yield from $walk->entryParts($foundation->feedUrlsByFeedId);
        yield $this->foundation($foundation, $provenance, $walk);
    }

    private function provenanceOf(User $user, ?string $sourceUrl): BackupProvenanceModel
    {
        return new BackupProvenanceModel(
            backupId: bin2hex(random_bytes(8)),
            createdAt: $this->clock->now(),
            sourceUrl: $sourceUrl,
            sourceEmail: $user->getEmail(),
        );
    }

    private function foundationSnapshot(int $userId): FoundationSnapshotModel
    {
        $subscriptions = ExportedFeed::withoutRepeats($this->subscriptions->findBackedUpForUserWithTags($userId));
        $feedsById = self::feedsById($subscriptions);

        return new FoundationSnapshotModel(
            $this->lines->accountLine(
                $this->users->getById($userId),
                $this->includedNetworks->includedNetworksFor($userId),
            ),
            array_map($this->lines->tagLine(...), self::parentsFirst($this->tags->findForUser($userId))),
            array_map($this->lines->savedSearchLine(...), $this->savedSearches->findForUser($userId)),
            array_map($this->lines->feedLine(...), array_values($feedsById)),
            array_map($this->lines->subscriptionLine(...), $subscriptions),
            array_map(static fn (ExportedSubscriptionModel $exported): string => $exported->feedUrl, $feedsById),
        );
    }

    private function foundation(
        FoundationSnapshotModel $snapshot,
        BackupProvenanceModel $provenance,
        BackupPartWalk $walk,
    ): BackupPartModel {
        $header = $this->lines->foundationHeader($provenance, $walk->partsWritten() + 1, $walk->totals());

        return $snapshot->part($header, $this->lines->footerLine($snapshot->counts()));
    }

    /**
     * @param list<ExportedSubscriptionModel> $subscriptions
     *
     * @return array<int, ExportedSubscriptionModel> one subscription per exported feed, by the feed's id
     */
    private static function feedsById(array $subscriptions): array
    {
        $feeds = [];
        foreach ($subscriptions as $exported) {
            $feedId = $exported->subscription->getFeed()->getId();
            if (null !== $feedId) {
                $feeds[$feedId] = $exported;
            }
        }

        return $feeds;
    }

    /**
     * A child names its parent, and the restore resolves names in file order.
     *
     * @param list<Tag> $tags
     *
     * @return list<Tag>
     */
    private static function parentsFirst(array $tags): array
    {
        $tree = TagTree::fromTags($tags);
        usort($tags, static fn (Tag $left, Tag $right): int => $tree->depthOf($left->requireId())
            <=> $tree->depthOf($right->requireId()));

        return $tags;
    }
}
