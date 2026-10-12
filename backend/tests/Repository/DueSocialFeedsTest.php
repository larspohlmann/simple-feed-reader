<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DueSocialFeedsTest extends DbTestCase
{
    private const string NOW = '2026-10-12 12:00:00';

    public function testAFeedWhoseAccountNeedsReconnectingIsNeverDue(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('due-social@example.test');
        $connected = SocialAccounts::blueskyOf($user);
        $stale = SocialAccounts::mastodonOf($user);
        $stale->markNeedsReconnect();
        $this->entityManager->persist($connected);
        $this->entityManager->persist($stale);
        $reachable = $this->privateFeed($connected, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        $paused = $this->privateFeed($stale, 'social:mastodon:2:home', SourceFormat::MASTODON_API);
        $rss = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($rss);
        $this->entityManager->flush();
        $now = new \DateTimeImmutable(self::NOW);

        $due = $this->feeds()->findDue(new DueFeedCriteria($now), 10);

        $expected = [$reachable->requireId(), $rss->requireId()];
        sort($expected);
        $dueIds = array_map(static fn (Feed $feed): int => $feed->requireId(), $due);
        sort($dueIds);
        self::assertSame($expected, $dueIds);
        self::assertSame(2, $this->feeds()->countDue(new DueFeedCriteria($now)));
        self::assertSame([], $this->feeds()->findDue(new DueFeedCriteria($now, feedId: $paused->requireId()), 10));
    }

    private function privateFeed(SocialAccount $account, string $url, string $sourceFormat): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new SocialFeed($account, $feed, SocialFeedKind::Home, null));

        return $feed;
    }

    private function feeds(): FeedRepository
    {
        /** @var FeedRepository $feeds */
        $feeds = $this->entityManager->getRepository(Feed::class);

        return $feeds;
    }
}
