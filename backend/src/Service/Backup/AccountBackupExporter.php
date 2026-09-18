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
 * Streams one account's whole state as a sequence of gzip-compressed backup
 * parts, in the order the archive keeps them: every entries part first, the
 * foundation (account, tags, feeds, subscriptions) last.
 *
 * The foundation is written last because it is the only part whose header
 * can declare the total part count and the entry/state totals — both are
 * unknowable until the entry walk has finished. Entries and their states are
 * read in ascending-id keyset batches with `$em->clear()` between them,
 * because an account's entries do not fit in memory at once (the design spec
 * measured 349.6 MiB for a buffered read). That walk detaches every managed
 * entity it touches, including the User this method was called with, so the
 * foundation re-fetches the account, tags, saved searches and subscriptions
 * by id afterwards rather than reusing anything the walk saw.
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
        $feedUrls = [];
        foreach ($this->subscriptions->findForUserWithTags($userId) as $subscription) {
            $feed = $subscription->getFeed();
            $feedId = $feed->getId();
            if (null !== $feedId) {
                $feedUrls[$feedId] = $feed->getUrl();
            }
        }

        return $feedUrls;
    }

    private function foundation(int $userId, BackupProvenance $provenance, BackupPartWalk $walk): BackupPart
    {
        $user = $this->users->getById($userId);
        $counts = ['tag' => 0, 'savedSearch' => 0, 'feed' => 0, 'subscription' => 0];
        $lines = [$this->lines->accountLine($user)];

        foreach ($this->tags->findForUser($userId) as $tag) {
            $lines[] = $this->lines->tagLine($tag);
            ++$counts['tag'];
        }

        foreach ($this->savedSearches->findForUser($userId) as $savedSearch) {
            $lines[] = $this->lines->savedSearchLine($savedSearch);
            ++$counts['savedSearch'];
        }

        $subscriptions = $this->subscriptions->findForUserWithTags($userId);
        foreach ($this->feedsById($subscriptions) as $feed) {
            $lines[] = $this->lines->feedLine($feed);
            ++$counts['feed'];
        }

        foreach ($subscriptions as $subscription) {
            $lines[] = $this->lines->subscriptionLine($subscription);
            ++$counts['subscription'];
        }

        $totals = $walk->totals();
        $header = $this->lines->foundationHeader($provenance, $walk->partsWritten() + 1, $totals);
        $footer = $this->lines->encode([
            'kind' => BackupSchema::KIND_FOOTER,
            'counts' => [...$counts, 'entry' => 0, 'entryState' => 0],
        ]);

        return BackupPart::foundation(BackupPart::gzip(implode("\n", [$header, ...$lines, $footer]) . "\n"));
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
