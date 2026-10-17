<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;

final class ExportableSubscriptionsTest extends DbTestCase
{
    public function testAnExportLeavesThePrivateSocialFeedsOut(): void
    {
        $user = new User('exportable@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $rss = $this->subscribed($user, 'https://example.com/feed.xml', SourceFormat::XML);
        $private = $this->subscribed($user, 'social:mastodon:1:home', SourceFormat::MASTODON_API);
        $this->entityManager->flush();

        self::assertSame([$rss], $this->subscriptions()->findExportableForUserWithTags($user->requireId()));
        self::assertSame([$rss, $private], $this->subscriptions()->findForUserWithTags($user->requireId()));
    }

    public function testAnAuthorFeedIsExportableAndEveryOtherPrivateFeedIsNot(): void
    {
        $user = new User('exportable-author@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($user));
        $author = $this->subscribed($user, 'social:bluesky:1:author:did:plc:rusty', SourceFormat::BLUESKY_API);
        $list = $this->subscribed($user, 'social:bluesky:1:list:at://list', SourceFormat::BLUESKY_API);
        $this->entityManager->persist(
            new SocialFeed($account, $author->getFeed(), SocialFeedKind::Author, 'did:plc:rusty'),
        );
        $this->entityManager->persist(
            new SocialFeed($account, $list->getFeed(), SocialFeedKind::UserList, 'at://list'),
        );
        $this->entityManager->flush();

        self::assertSame([$author], $this->subscriptions()->findExportableForUserWithTags($user->requireId()));
        self::assertSame([$author], $this->subscriptions()->findBackedUpForUserWithTags($user->requireId()));
    }

    public function testAnOpmlLeavesTheSavedFeedsOutAndABackupKeepsThem(): void
    {
        $user = new User('exportable-saved@example.com', new \DateTimeImmutable('2026-10-17T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $rss = $this->subscribed($user, 'https://example.com/saved-test.xml', SourceFormat::XML);
        $this->subscribed($user, 'social:mastodon:2:home', SourceFormat::MASTODON_API);
        $saved = $this->subscribed($user, sprintf('saved:%d:bluesky', $user->requireId()), SourceFormat::SAVED);
        $this->entityManager->flush();

        self::assertSame([$rss], $this->subscriptions()->findExportableForUserWithTags($user->requireId()));
        self::assertSame([$rss, $saved], $this->subscriptions()->findBackedUpForUserWithTags($user->requireId()));
    }

    public function testTheCapCountsNoSavedFeed(): void
    {
        $user = new User('count-saved@example.com', new \DateTimeImmutable('2026-10-17T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $rss = $this->subscribed($user, 'https://example.com/counted.xml', SourceFormat::XML);
        $saved = $this->subscribed($user, sprintf('saved:%d', $user->requireId()), SourceFormat::SAVED);
        $other = new User('count-saved-other@example.com', new \DateTimeImmutable('2026-10-17T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $otherSaved = new Subscription($other, $saved->getFeed(), new \DateTimeImmutable('2026-10-17T00:00:00Z'));
        $this->entityManager->persist($otherSaved);
        $this->entityManager->flush();

        self::assertSame(1, $this->subscriptions()->countForUser($user->requireId()));
        $savedUrl = sprintf('saved:%d', $user->requireId());
        self::assertSame($saved, $this->subscriptions()->findOneForUserAndFeedUrl($user->requireId(), $savedUrl));
        self::assertSame($otherSaved, $this->subscriptions()->findOneForUserAndFeedUrl($other->requireId(), $savedUrl));
        self::assertNull(
            $this->subscriptions()->findOneForUserAndFeedUrl($other->requireId(), $rss->getFeed()->getUrl()),
        );
    }

    private function subscribed(User $user, string $url, string $sourceFormat): Subscription
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function subscriptions(): SubscriptionRepository
    {
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = $this->entityManager->getRepository(Subscription::class);

        return $subscriptions;
    }
}
