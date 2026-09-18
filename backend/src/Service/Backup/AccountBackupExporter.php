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
 * known. The entry walk clear()s the entity manager, so the foundation
 * re-fetches everything by user id instead of reusing the caller's User.
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
        $walk = new BackupPartWalk($this->em, $this->entries, $this->entryStates, $this->lines, $provenance, $userId);

        yield from $walk->entryParts($this->feedUrlsByFeedId($userId));
        yield $this->foundation($userId, $provenance, $walk);
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

    /**
     * @return array<int, string>
     */
    private function feedUrlsByFeedId(int $userId): array
    {
        return array_map(
            static fn (Feed $feed): string => $feed->getUrl(),
            $this->feedsById($this->subscriptions->findForUserWithTags($userId)),
        );
    }

    private function foundation(int $userId, BackupProvenance $provenance, BackupPartWalk $walk): BackupPart
    {
        $subscriptions = $this->subscriptions->findForUserWithTags($userId);
        $tagLines = array_map($this->lines->tagLine(...), $this->tags->findForUser($userId));
        $savedSearchLines = array_map($this->lines->savedSearchLine(...), $this->savedSearches->findForUser($userId));
        $feedLines = array_map($this->lines->feedLine(...), array_values($this->feedsById($subscriptions)));
        $subscriptionLines = array_map($this->lines->subscriptionLine(...), $subscriptions);

        $header = $this->lines->foundationHeader($provenance, $walk->partsWritten() + 1, $walk->totals());
        $footer = $this->lines->footerLine([
            BackupSchema::KIND_TAG => \count($tagLines),
            BackupSchema::KIND_SAVED_SEARCH => \count($savedSearchLines),
            BackupSchema::KIND_FEED => \count($feedLines),
            BackupSchema::KIND_SUBSCRIPTION => \count($subscriptionLines),
        ]);
        $lines = [
            $header,
            $this->lines->accountLine($this->users->getById($userId)),
            ...$tagLines,
            ...$savedSearchLines,
            ...$feedLines,
            ...$subscriptionLines,
            $footer,
        ];

        return BackupPart::foundation(BackupPart::gzip(implode("\n", $lines) . "\n"));
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
