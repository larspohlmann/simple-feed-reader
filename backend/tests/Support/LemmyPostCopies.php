<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** One Lemmy post in two community feeds the reader follows, Local and All: two copies of one canonical item. */
final readonly class LemmyPostCopies
{
    public const string LOCAL_FEED = 'https://lemmy.example/feeds/local.xml';
    public const string ALL_FEED = 'https://lemmy.example/feeds/all.xml';

    private function __construct(
        public Subscription $local,
        public Subscription $all,
        public Entry $localCopy,
        public Entry $allCopy,
    ) {
    }

    public static function persistPair(EntityManagerInterface $entityManager, User $reader): self
    {
        $subscriptions = new NetworkFeedSubscriptions($entityManager);
        $local = $subscriptions->subscribe($reader, self::LOCAL_FEED);
        $all = $subscriptions->subscribe($reader, self::ALL_FEED);

        return new self(
            $local,
            $all,
            self::persistCopy($entityManager, $local->getFeed(), 'local-copy'),
            self::persistCopy($entityManager, $all->getFeed(), 'all-copy'),
        );
    }

    public static function persistCopy(
        EntityManagerInterface $entityManager,
        Feed $feed,
        string $guid,
        string $publishedAt = '2026-11-14T00:00:00Z',
    ): Entry {
        $copy = LemmyPostEntries::buildEntry($feed, guid: $guid, publishedAt: $publishedAt);
        $entityManager->persist($copy);
        CanonicalItems::linkAsIngested($entityManager, $copy);
        $entityManager->flush();

        return $copy;
    }
}
