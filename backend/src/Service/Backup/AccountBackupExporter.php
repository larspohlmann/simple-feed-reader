<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Repository\SavedSearchRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * One account as gzipped backup parts: every entry part first, the foundation
 * last, because only then are the part count and totals its header declares
 * known. The foundation's record lines are captured up front, before the entry
 * walk clear()s the entity manager, so the parts and the foundation name the
 * same subscriptions and feeds even if the account changes mid-export.
 */
final readonly class AccountBackupExporter
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private TagRepository $tags,
        private SavedSearchRepository $savedSearches,
        private SubscriptionRepository $subscriptions,
        private EntryRepository $entries,
        private EntryStateRepository $entryStates,
        private BackupLines $lines,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return \Generator<int, BackupPart>
     */
    public function parts(User $user, ?string $sourceUrl): \Generator
    {
        $userId = $user->getId() ?? throw new \LogicException('Cannot export an unsaved account.');
        $provenance = $this->provenanceOf($user, $sourceUrl);
        $foundation = $this->foundationSnapshot($userId);
        $walk = new BackupPartWalk($this->em, $this->entries, $this->entryStates, $this->lines, $provenance, $userId);

        yield from $walk->entryParts($foundation->feedUrlsByFeedId);
        yield $this->foundation($foundation, $provenance, $walk);
    }

    private function provenanceOf(User $user, ?string $sourceUrl): BackupProvenance
    {
        return new BackupProvenance(
            backupId: bin2hex(random_bytes(8)),
            createdAt: $this->clock->now(),
            sourceUrl: $sourceUrl,
            sourceEmail: $user->getEmail(),
        );
    }

    private function foundationSnapshot(int $userId): FoundationSnapshot
    {
        $subscriptions = $this->subscriptions->findForUserWithTags($userId);
        $feedsById = $this->feedsById($subscriptions);

        return new FoundationSnapshot(
            $this->lines->accountLine($this->users->getById($userId)),
            array_map($this->lines->tagLine(...), $this->tags->findForUser($userId)),
            array_map($this->lines->savedSearchLine(...), $this->savedSearches->findForUser($userId)),
            array_map($this->lines->feedLine(...), array_values($feedsById)),
            array_map($this->lines->subscriptionLine(...), $subscriptions),
            array_map(static fn (Feed $feed): string => $feed->getUrl(), $feedsById),
        );
    }

    private function foundation(
        FoundationSnapshot $snapshot,
        BackupProvenance $provenance,
        BackupPartWalk $walk,
    ): BackupPart {
        $header = $this->lines->foundationHeader($provenance, $walk->partsWritten() + 1, $walk->totals());

        return $snapshot->part($header, $this->lines->footerLine($snapshot->counts()));
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return array<int, Feed>
     */
    private function feedsById(array $subscriptions): array
    {
        $feeds = [];
        foreach ($subscriptions as $subscription) {
            $feed = $subscription->getFeed();
            $feedId = $feed->getId();
            if (null !== $feedId) {
                $feeds[$feedId] = $feed;
            }
        }

        return $feeds;
    }
}
