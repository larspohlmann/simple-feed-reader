<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Subscription\Model\TagStyleModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\TagJoins;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class BulkSubscriberTest extends DbTestCase
{
    use SeedsUsers;

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function subscriber(): BulkSubscriber
    {
        $subscriber = self::getContainer()->get(BulkSubscriber::class);
        self::assertInstanceOf(BulkSubscriber::class, $subscriber);

        return $subscriber;
    }

    public function testSubscribesEachItemOnceAndTagsItUnderItsCategory(): void
    {
        $user = $this->user('bulk@example.com');

        $style = new TagStyleModel('#3b82f6', 'memory');
        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://a.example.com/rss.xml', 'A Feed', 'Technology', $style),
            new BulkSubscribeItemModel('https://b.example.com/rss.xml', 'B Feed', 'Technology', $style),
        ]);

        self::assertSame(2, $result->imported);
        self::assertCount(1, $result->tagsCreated);

        $tag = $result->tagsCreated[0];
        self::assertSame('Technology', $tag->getName());
        self::assertSame('#3b82f6', $tag->getColor());
        self::assertSame('memory', $tag->getIcon());
    }

    public function testSeedsTheFeedTitleOnlyWhenTheSharedFeedRowIsNew(): void
    {
        $existing = new Feed('https://shared.example.com/rss.xml');
        $existing->setTitle('Publisher Title');
        $this->em()->persist($existing);
        $this->em()->flush();

        $user = $this->user('titles@example.com');

        $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://shared.example.com/rss.xml', 'Catalog Title', null, null),
            new BulkSubscribeItemModel('https://fresh.example.com/rss.xml', 'Catalog Title', null, null),
        ]);

        $shared = $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://shared.example.com/rss.xml']);
        $fresh = $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://fresh.example.com/rss.xml']);

        self::assertNotNull($shared);
        self::assertNotNull($fresh);
        self::assertSame('Publisher Title', $shared->getTitle(), 'an existing shared Feed row is never retitled');
        self::assertSame('Catalog Title', $fresh->getTitle(), 'a new Feed row is seeded from the catalog');
    }

    public function testANewFeedIsScheduledForTheNextRefresh(): void
    {
        $clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        self::getContainer()->set(ClockInterface::class, $clock);
        $user = $this->user('due@example.com');

        $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://due.example.com/rss.xml', 'Due Feed', null, null),
        ]);

        $feed = $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://due.example.com/rss.xml']);
        self::assertNotNull($feed);
        self::assertEquals($clock->now(), $feed->getNextFetchAt());
    }

    public function testReusesAnExistingTagAndLeavesItsStylingAlone(): void
    {
        $user = $this->user('reuse@example.com');

        $existing = new Tag($user, 'Technology');
        $existing->setColor('#123456');
        $existing->setIcon('star');
        $this->em()->persist($existing);
        $this->em()->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel(
                'https://c.example.com/rss.xml',
                'C Feed',
                'Technology',
                new TagStyleModel('#3b82f6', 'memory'),
            ),
        ]);

        self::assertSame(1, $result->imported);
        self::assertCount(0, $result->tagsCreated, 'a reused tag was not created');

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Tag::class)->findOneBy(['name' => 'Technology']);
        self::assertNotNull($reloaded);
        self::assertSame('#123456', $reloaded->getColor());
        self::assertSame('star', $reloaded->getIcon());
    }

    public function testCountsARepeatedUrlAsAlreadySubscribedRatherThanPersistingTwice(): void
    {
        $user = $this->user('dupe@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://d.example.com/rss.xml', 'D Feed', null, null),
            new BulkSubscribeItemModel('https://d.example.com/rss.xml', 'D Feed', null, null),
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
        self::assertCount(1, $this->em()->getRepository(Subscription::class)->findAll());
    }

    public function testRejectsAnUnusableUrlWithoutAbortingTheBatch(): void
    {
        $user = $this->user('invalid@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('not-a-url', 'Bad', null, null),
            new BulkSubscribeItemModel('https://e.example.com/rss.xml', 'E Feed', null, null),
        ]);

        self::assertSame(1, $result->invalid);
        self::assertSame(1, $result->imported);
    }

    public function testPositionsContinueAfterWhatTheUserAlreadyHas(): void
    {
        $user = $this->user('positions@example.com');
        $existingFeed = new Feed('https://existing.example.com/rss.xml');
        $existingSubscription = new Subscription($user, $existingFeed, new \DateTimeImmutable('2026-07-01 00:00:00'));
        $existingSubscription->setPosition(4);
        $existingTag = new Tag($user, 'Existing');
        $existingTag->setPosition(2);
        $existingSubscription->addTag($existingTag, 6);
        $this->em()->persist($existingFeed);
        $this->em()->persist($existingTag);
        $this->em()->persist($existingSubscription);
        $this->em()->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://one.example.com/rss.xml', 'One', 'Existing', null),
            new BulkSubscribeItemModel('https://two.example.com/rss.xml', 'Two', 'Fresh', null),
            new BulkSubscribeItemModel('https://three.example.com/rss.xml', 'Three', 'fresh', null),
        ]);

        self::assertSame(3, $result->imported);
        self::assertCount(1, $result->tagsCreated);
        self::assertSame('Fresh', $result->tagsCreated[0]->getName());
        self::assertSame(3, $result->tagsCreated[0]->getPosition());
        $one = $this->subscriptionTo($user, 'https://one.example.com/rss.xml');
        $two = $this->subscriptionTo($user, 'https://two.example.com/rss.xml');
        $three = $this->subscriptionTo($user, 'https://three.example.com/rss.xml');
        self::assertSame(5, $one->getPosition());
        self::assertSame(6, $two->getPosition());
        self::assertSame(7, $three->getPosition());
        self::assertSame(7, TagJoins::positionOf($one, $existingTag));
        self::assertSame(0, TagJoins::positionOf($two, $result->tagsCreated[0]));
        self::assertSame(1, TagJoins::positionOf($three, $result->tagsCreated[0]));
    }

    public function testTheCapCountsTheSubscriptionsThisBatchAlreadyMade(): void
    {
        $user = $this->user('cap@example.com');
        $user->setMaxSubscriptions(2);
        $existingFeed = new Feed('https://existing.example.com/rss.xml');
        $this->em()->persist($existingFeed);
        $this->em()->persist(new Subscription($user, $existingFeed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->em()->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://first.example.com/rss.xml', 'First', null, null),
            new BulkSubscribeItemModel('https://second.example.com/rss.xml', 'Second', null, null),
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->skippedOverLimit);
        self::assertNull(
            $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://second.example.com/rss.xml']),
        );
    }

    public function testAnOverlongTagNameIsCutToTheColumnAndMatchedCaseInsensitively(): void
    {
        $user = $this->user('long-tag@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel(
                'https://long.example.com/rss.xml',
                'Long',
                'L' . str_repeat('t', 119),
                null,
            ),
            new BulkSubscribeItemModel(
                'https://longer.example.com/rss.xml',
                'Longer',
                'l' . str_repeat('T', 119),
                null,
            ),
        ]);

        self::assertSame(2, $result->imported);
        self::assertCount(1, $result->tagsCreated);
        self::assertSame('L' . str_repeat('t', 99), $result->tagsCreated[0]->getName());
    }

    private function subscriptionTo(User $user, string $feedUrl): Subscription
    {
        $feed = $this->em()->getRepository(Feed::class)->findOneBy(['url' => $feedUrl]);
        self::assertNotNull($feed);
        $subscription = $this->em()->getRepository(Subscription::class)->findOneBy(['user' => $user, 'feed' => $feed]);
        self::assertNotNull($subscription);

        return $subscription;
    }
}
