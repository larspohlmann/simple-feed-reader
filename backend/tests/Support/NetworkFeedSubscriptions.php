<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
use Doctrine\ORM\EntityManagerInterface;

/** Subscriptions to private, profile and plain feeds, for the tests of a network's combined view. */
final readonly class NetworkFeedSubscriptions
{
    public const string BLUESKY_PROFILE = 'https://bsky.app/profile/alice.bsky.social/rss';
    public const string MASTODON_PROFILE = 'https://mastodon.social/@Gargron.rss';
    public const string PLAIN = 'https://example.com/feed.xml';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public static function blueskyHome(int $accountId): string
    {
        return 'social:bluesky:' . $accountId . ':home';
    }

    public function subscribe(User $user, string $feedUrl, string $sourceFormat = SourceFormat::XML): Subscription
    {
        $feed = new Feed($feedUrl);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-10-01T10:00:00Z'));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    public function post(Subscription $subscription, string $guid, string $publishedAt): Entry
    {
        $published = new \DateTimeImmutable($publishedAt);
        $entry = new Entry($subscription->getFeed(), $guid, null, $guid, $published, $published);
        $entry->setPublishedAt($published);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
