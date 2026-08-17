<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Streams one account's whole state as NDJSON lines, in the file order
 * BackupReader enforces: header, account, tags, feeds, subscriptions,
 * entries, entry states, footer.
 *
 * Entries and entry states are read in ascending-id keyset batches with
 * `$em->clear()` between them, because an account's entries do not fit in
 * memory at once (the design spec measured 349.6 MiB for a buffered read).
 * The scalar ids the batch walk needs — the user id and the subscribed feed
 * ids — are captured before that walk starts, since clear() detaches every
 * managed entity, including the User this method was called with.
 */
final readonly class AccountBackupExporter
{
    private const int ENTRY_BATCH = 500;

    public function __construct(
        private EntityManagerInterface $em,
        private TagRepository $tags,
        private SubscriptionRepository $subscriptions,
        private EntryRepository $entries,
        private EntryStateRepository $entryStates,
        private RecommendationSettingsRepository $recommendationSettings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return \Generator<int, string>
     */
    public function lines(User $user, ?string $sourceUrl): \Generator
    {
        $userId = $user->getId() ?? throw new \LogicException('Cannot export an unsaved account.');
        $counts = ['tag' => 0, 'feed' => 0, 'subscription' => 0, 'entry' => 0, 'entryState' => 0];

        yield $this->encode($this->headerLine($user, $sourceUrl));
        yield $this->encode($this->accountLine($user));

        foreach ($this->tags->findForUser($userId) as $tag) {
            yield $this->encode($this->tagLine($tag));
            ++$counts['tag'];
        }

        $subscriptions = $this->subscriptions->findForUserWithTags($userId);
        $feeds = $this->feedsById($subscriptions);

        foreach ($feeds as $feed) {
            yield $this->encode($this->feedLine($feed));
            ++$counts['feed'];
        }

        foreach ($subscriptions as $subscription) {
            yield $this->encode($this->subscriptionLine($subscription));
            ++$counts['subscription'];
        }

        // Scalar feed id => url, read while $feeds is still managed — the
        // per-batch clear() below detaches it.
        $feedUrlsByFeedId = array_map(static fn (Feed $feed): string => $feed->getUrl(), $feeds);

        yield from $this->entryLines($feedUrlsByFeedId, $counts);
        yield from $this->entryStateLines($userId, $counts);

        yield $this->encode(['kind' => BackupSchema::KIND_FOOTER, 'counts' => $counts]);
    }

    /**
     * @param array<int, string> $feedUrlsByFeedId
     * @param array<string, int> $counts
     *
     * @return \Generator<int, string>
     */
    private function entryLines(array $feedUrlsByFeedId, array &$counts): \Generator
    {
        foreach ($feedUrlsByFeedId as $feedId => $feedUrl) {
            yield from $this->entryLinesForFeed($feedId, $feedUrl, $counts);
        }
    }

    /**
     * @param array<string, int> $counts
     *
     * @return \Generator<int, string>
     */
    private function entryLinesForFeed(int $feedId, string $feedUrl, array &$counts): \Generator
    {
        $lastId = 0;
        do {
            $batch = $this->entries->forFeedAfterId($feedId, $lastId, self::ENTRY_BATCH);
            foreach ($batch as $entry) {
                yield $this->encode($this->entryLine($entry, $feedUrl));
                ++$counts['entry'];
                $lastId = $entry->getId() ?? $lastId;
            }
            $batchSize = \count($batch);
            $this->em->clear();
        } while (self::ENTRY_BATCH === $batchSize);
    }

    /**
     * @param array<string, int> $counts
     *
     * @return \Generator<int, string>
     */
    private function entryStateLines(int $userId, array &$counts): \Generator
    {
        $lastEntryId = 0;
        do {
            $batch = $this->entryStates->forUserAfterEntryId($userId, $lastEntryId, self::ENTRY_BATCH);
            foreach ($batch as $state) {
                yield $this->encode($this->entryStateLine($state));
                ++$counts['entryState'];
                $lastEntryId = $state->getEntry()->getId() ?? $lastEntryId;
            }
            $batchSize = \count($batch);
            $this->em->clear();
        } while (self::ENTRY_BATCH === $batchSize);
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

    /**
     * @return array<string, mixed>
     */
    private function headerLine(User $user, ?string $sourceUrl): array
    {
        return [
            'kind' => BackupSchema::KIND_HEADER,
            'schemaVersion' => BackupSchema::VERSION,
            'createdAt' => $this->formatDate($this->clock->now()),
            'sourceUrl' => $sourceUrl,
            'sourceEmail' => $user->getEmail(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountLine(User $user): array
    {
        return [
            'kind' => BackupSchema::KIND_ACCOUNT,
            'locale' => $user->getLocale(),
            'scrapeFallbackEnabled' => $user->getPreferences()->isScrapeFallbackEnabled(),
            'recommendationSettings' => $this->recommendationSettingsFields($user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recommendationSettingsFields(User $user): ?array
    {
        $settings = $this->recommendationSettings->findForUser($user);
        if (null === $settings) {
            return null;
        }

        $values = $settings->values();

        return [
            'guidancePrompt' => $values->guidancePrompt,
            'favoritesCap' => $values->favoritesCap,
            'keptCap' => $values->keptCap,
            'viewedCap' => $values->viewedCap,
            'candidatePoolSize' => $values->candidatePoolSize,
            'lookbackDays' => $values->lookbackDays,
            'picksLimit' => $values->picksLimit,
            'contextWindow' => $values->contextWindow,
            'batchCount' => $values->batchCount,
            'debugEnabled' => $values->debugEnabled,
            'autoGenerateIntervalHours' => $values->autoGenerateIntervalHours,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tagLine(Tag $tag): array
    {
        return [
            'kind' => BackupSchema::KIND_TAG,
            'name' => $tag->getName(),
            'color' => $tag->getColor(),
            'icon' => $tag->getIcon(),
            'position' => $tag->getPosition(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feedLine(Feed $feed): array
    {
        return [
            'kind' => BackupSchema::KIND_FEED,
            'url' => $feed->getUrl(),
            'siteUrl' => $feed->getSiteUrl(),
            'title' => $feed->getTitle(),
            'description' => $feed->getDescription(),
            'faviconUrl' => $feed->getFaviconUrl(),
            'sourceFormat' => $feed->getSourceFormat(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionLine(Subscription $subscription): array
    {
        return [
            'kind' => BackupSchema::KIND_SUBSCRIPTION,
            'feedUrl' => $subscription->getFeed()->getUrl(),
            'customTitle' => $subscription->getCustomTitle(),
            'position' => $subscription->getPosition(),
            'markedReadUntil' => $this->formatDateOrNull($subscription->getMarkedReadUntil()),
            'createdAt' => $this->formatDate($subscription->getCreatedAt()),
            'tags' => $this->subscriptionTagRefs($subscription),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subscriptionTagRefs(Subscription $subscription): array
    {
        return array_map(
            static fn (SubscriptionTag $subscriptionTag): array => [
                'name' => $subscriptionTag->getTag()->getName(),
                'position' => $subscriptionTag->getPosition(),
            ],
            $subscription->getSubscriptionTags(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function entryLine(Entry $entry, string $feedUrl): array
    {
        return [
            'kind' => BackupSchema::KIND_ENTRY,
            'feedUrl' => $feedUrl,
            'guid' => $entry->getGuid(),
            'guidHash' => $entry->getGuidHash(),
            'url' => $entry->getUrl(),
            'title' => $entry->getTitle(),
            'author' => $entry->getAuthor(),
            'summary' => $entry->getSummary(),
            'contentHtml' => $entry->getContentHtml(),
            'imageUrl' => $entry->getImageUrl(),
            'imageWidth' => $entry->getImageWidth(),
            'imageHeight' => $entry->getImageHeight(),
            'publishedAt' => $this->formatDateOrNull($entry->getPublishedAt()),
            'createdAt' => $this->formatDate($entry->getCreatedAt()),
            'effectiveDate' => $this->formatDate($entry->getEffectiveDate()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryStateLine(EntryState $state): array
    {
        $entry = $state->getEntry();

        return [
            'kind' => BackupSchema::KIND_ENTRY_STATE,
            'feedUrl' => $entry->getFeed()->getUrl(),
            'guidHash' => $entry->getGuidHash(),
            'isRead' => $state->isRead(),
            'isFavorite' => $state->isFavorite(),
            'isKept' => $state->isKept(),
            'readAt' => $this->formatDateOrNull($state->getReadAt()),
            'isViewed' => $state->isViewed(),
            'viewedAt' => $this->formatDateOrNull($state->getViewedAt()),
        ];
    }

    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    private function formatDateOrNull(?\DateTimeImmutable $date): ?string
    {
        return null === $date ? null : $this->formatDate($date);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function encode(array $line): string
    {
        return json_encode($line, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
