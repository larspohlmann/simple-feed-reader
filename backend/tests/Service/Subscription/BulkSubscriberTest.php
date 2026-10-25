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
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\TagJoins;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class BulkSubscriberTest extends DbTestCase
{
    use SeedsUsers;

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
            new BulkSubscribeItemModel('https://a.example.com/rss.xml', 'A Feed', ['Technology'], $style),
            new BulkSubscribeItemModel('https://b.example.com/rss.xml', 'B Feed', ['Technology'], $style),
        ]);

        self::assertSame(2, $result->imported);
        self::assertCount(1, $result->tagsCreated);

        $tag = $result->tagsCreated[0];
        self::assertSame('Technology', $tag->getName());
        self::assertSame('#3b82f6', $tag->getColor());
        self::assertSame('memory', $tag->getIcon());
    }

    public function testABatchHoldsTheTagStructureLock(): void
    {
        if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite has no row locks; it serialises writers itself.');
        }
        $user = $this->user('batch-locks@example.com');
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://a.example.com/rss.xml', 'A Feed', ['A', 'B']),
        ]);

        self::assertCount(1, $recorder->queriesMatching('for update'));
    }

    public function testSeedsTheFeedTitleOnlyWhenTheSharedFeedRowIsNew(): void
    {
        $existing = new Feed('https://shared.example.com/rss.xml');
        $existing->setTitle('Publisher Title');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $user = $this->user('titles@example.com');

        $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://shared.example.com/rss.xml', 'Catalog Title'),
            new BulkSubscribeItemModel('https://fresh.example.com/rss.xml', 'Catalog Title'),
        ]);

        $shared = $this->entityManager
            ->getRepository(Feed::class)
            ->findOneBy(['url' => 'https://shared.example.com/rss.xml']);
        $fresh = $this->entityManager
            ->getRepository(Feed::class)
            ->findOneBy(['url' => 'https://fresh.example.com/rss.xml']);

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
            new BulkSubscribeItemModel('https://due.example.com/rss.xml', 'Due Feed'),
        ]);

        $feed = $this->entityManager
            ->getRepository(Feed::class)
            ->findOneBy(['url' => 'https://due.example.com/rss.xml']);
        self::assertNotNull($feed);
        self::assertEquals($clock->now(), $feed->getNextFetchAt());
    }

    public function testReusesAnExistingTagAndLeavesItsStylingAlone(): void
    {
        $user = $this->user('reuse@example.com');

        $existing = new Tag($user, 'Technology');
        $existing->setColor('#123456');
        $existing->setIcon('star');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel(
                'https://c.example.com/rss.xml',
                'C Feed',
                ['Technology'],
                new TagStyleModel('#3b82f6', 'memory'),
            ),
        ]);

        self::assertSame(1, $result->imported);
        self::assertCount(0, $result->tagsCreated, 'a reused tag was not created');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Tag::class)->findOneBy(['name' => 'Technology']);
        self::assertNotNull($reloaded);
        self::assertSame('#123456', $reloaded->getColor());
        self::assertSame('star', $reloaded->getIcon());
    }

    public function testCountsARepeatedUrlAsAlreadySubscribedRatherThanPersistingTwice(): void
    {
        $user = $this->user('dupe@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://d.example.com/rss.xml', 'D Feed'),
            new BulkSubscribeItemModel('https://d.example.com/rss.xml', 'D Feed'),
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
        self::assertCount(1, $this->entityManager->getRepository(Subscription::class)->findAll());
    }

    public function testRejectsAnUnusableUrlWithoutAbortingTheBatch(): void
    {
        $user = $this->user('invalid@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('not-a-url', 'Bad'),
            new BulkSubscribeItemModel('https://e.example.com/rss.xml', 'E Feed'),
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
        $this->entityManager->persist($existingFeed);
        $this->entityManager->persist($existingTag);
        $this->entityManager->persist($existingSubscription);
        $this->entityManager->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://one.example.com/rss.xml', 'One', ['Existing']),
            new BulkSubscribeItemModel('https://two.example.com/rss.xml', 'Two', ['Fresh']),
            new BulkSubscribeItemModel('https://three.example.com/rss.xml', 'Three', ['fresh']),
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
        $this->entityManager->persist($existingFeed);
        $this->entityManager->persist(
            new Subscription($user, $existingFeed, new \DateTimeImmutable('2026-07-01 00:00:00')),
        );
        $this->entityManager->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://first.example.com/rss.xml', 'First'),
            new BulkSubscribeItemModel('https://second.example.com/rss.xml', 'Second'),
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->skippedOverLimit);
        self::assertNull(
            $this->entityManager
                ->getRepository(Feed::class)
                ->findOneBy(['url' => 'https://second.example.com/rss.xml']),
        );
    }

    public function testAnOverlongTagNameIsCutToTheColumnAndMatchedCaseInsensitively(): void
    {
        $user = $this->user('long-tag@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel(
                'https://long.example.com/rss.xml',
                'Long',
                ['L' . str_repeat('t', 119)],
            ),
            new BulkSubscribeItemModel(
                'https://longer.example.com/rss.xml',
                'Longer',
                ['l' . str_repeat('T', 119)],
            ),
        ]);

        self::assertSame(2, $result->imported);
        self::assertCount(1, $result->tagsCreated);
        self::assertSame('L' . str_repeat('t', 99), $result->tagsCreated[0]->getName());
    }

    public function testAMultibyteTagNameIsCutToTheColumnByCharacters(): void
    {
        $user = $this->user('multibyte-tag@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://umlaut.example.com/rss.xml', 'Umlaut', [str_repeat('ä', 120)]),
        ]);

        self::assertSame(str_repeat('ä', 100), $result->tagsCreated[0]->getName());
    }

    public function testANewParentTagIsCreatedWithItsChildAndReportedWithIt(): void
    {
        $user = $this->user('new-parent@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://nest.example.com/rss.xml', 'Nest', ['Parent', 'Child']),
        ]);

        self::assertSame(['Child', 'Parent'], $this->namesOf($result->tagsCreated));
        self::assertSame('Parent', $result->tagsCreated[0]->getParent()?->getName());
    }

    public function testAnExistingParentTagIsReusedAndNotReportedAsCreated(): void
    {
        $user = $this->user('existing-parent@example.com');
        $existingParent = new Tag($user, 'Parent');
        $this->entityManager->persist($existingParent);
        $this->entityManager->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://reuse.example.com/rss.xml', 'Reuse', ['Parent', 'Child']),
        ]);

        self::assertSame(['Child'], $this->namesOf($result->tagsCreated));
        self::assertSame($existingParent, $result->tagsCreated[0]->getParent());
    }

    public function testATagTheBatchAlreadyKnowsIsNotLookedUpAgain(): void
    {
        $user = $this->user('lookup@example.com');
        $subscriber = $this->subscriber();
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $result = $subscriber->subscribeAll($user, [
            new BulkSubscribeItemModel('https://a.example.com/rss.xml', 'A Feed', ['Technology']),
            new BulkSubscribeItemModel('https://b.example.com/rss.xml', 'B Feed', ['TECHNOLOGY']),
            new BulkSubscribeItemModel('https://c.example.com/rss.xml', 'C Feed', ['technology']),
        ]);

        self::assertSame(3, $result->imported);
        self::assertCount(1, $result->tagsCreated);
        $tag = $result->tagsCreated[0];
        foreach (['a', 'b', 'c'] as $letter) {
            $subscription = $this->subscriptionTo($user, sprintf('https://%s.example.com/rss.xml', $letter));
            self::assertTrue(
                $subscription->getTags()->contains($tag),
                sprintf('subscription "%s" carries the tag', $letter),
            );
        }

        self::assertCount(
            1,
            $recorder->queriesMatching('lower('),
            'one tag lookup for three items naming one tag: the batch answers the other two',
        );
    }

    public function testAStoredTagFoundOnceIsNotLookedUpAgainAsAnOuterName(): void
    {
        $user = $this->user('stored-lookup@example.com');
        $stored = new Tag($user, 'Tech');
        $this->entityManager->persist($stored);
        $this->entityManager->flush();
        $subscriber = $this->subscriber();
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $result = $subscriber->subscribeAll($user, [
            new BulkSubscribeItemModel('https://tech.example.com/rss.xml', 'Tech Feed', ['Tech']),
            new BulkSubscribeItemModel('https://web.example.com/rss.xml', 'Web Feed', ['Tech', 'Web']),
        ]);

        self::assertSame(['Web'], $this->namesOf($result->tagsCreated));
        self::assertSame($stored, $result->tagsCreated[0]->getParent());
        self::assertCount(
            2,
            $recorder->queriesMatching('lower('),
            'one lookup for Tech, found as the first item\'s tag, and one for the new Web; Tech as Web\'s parent '
            . 'is answered by the batch',
        );
    }

    public function testNamesThatDifferOnlyByAnAccentAreTwoTags(): void
    {
        $user = $this->user('accent-twins@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://cafe-accented.example.com/rss.xml', 'Accented', ['Café']),
            new BulkSubscribeItemModel('https://cafe-plain.example.com/rss.xml', 'Plain', ['Cafe']),
        ]);

        self::assertSame(['Café', 'Cafe'], $this->namesOf($result->tagsCreated));
    }

    public function testAStoredTagIsNotMatchedByItsNameWithoutTheAccent(): void
    {
        $user = $this->user('accent-stored@example.com');
        $this->entityManager->persist(new Tag($user, 'Café'));
        $this->entityManager->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://cafe-later.example.com/rss.xml', 'Later', ['Cafe']),
        ]);

        self::assertSame(['Cafe'], $this->namesOf($result->tagsCreated));
    }

    public function testAThreeLevelPathNestsEachTagUnderTheNext(): void
    {
        $user = $this->user('three-levels@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://three.example.com/rss.xml', 'Three', ['A', 'B', 'C']),
        ]);

        self::assertSame(['C', 'B', 'A'], $this->namesOf($result->tagsCreated));
        $tagB = $result->tagsCreated[0]->getParent();
        self::assertSame('B', $tagB?->getName());
        self::assertSame('A', $tagB->getParent()?->getName());
    }

    public function testAPathDeeperThanTheLimitKeepsItsOutermostLevels(): void
    {
        $user = $this->user('clamped@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://clamped.example.com/rss.xml', 'Clamped', ['A', 'B', 'C', 'D']),
        ]);

        $subscription = $this->subscriptionTo($user, 'https://clamped.example.com/rss.xml');
        self::assertSame(['C', 'B', 'A'], $this->namesOf($result->tagsCreated));
        self::assertSame(['C'], $this->namesOf($subscription->getTags()->toArray()));
    }

    public function testATagNestsUnderAnExistingChildTag(): void
    {
        $user = $this->user('under-child@example.com');
        $tech = new Tag($user, 'Tech');
        $web = new Tag($user, 'Web');
        $web->setParent($tech);
        $this->entityManager->persist($tech);
        $this->entityManager->persist($web);
        $this->entityManager->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://frontend.example.com/rss.xml', 'Frontend', ['Web', 'Frontend']),
        ]);

        self::assertSame(['Frontend'], $this->namesOf($result->tagsCreated));
        self::assertSame($web, $result->tagsCreated[0]->getParent());
    }

    public function testTagsCreatedEarlierInTheBatchCountTowardsTheDepth(): void
    {
        $user = $this->user('batch-depth@example.com');

        $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://first.example.com/rss.xml', 'First', ['A', 'B', 'C']),
            new BulkSubscribeItemModel('https://second.example.com/rss.xml', 'Second', ['X', 'A']),
        ]);

        self::assertNull($this->storedTag($user, 'A')->getParent());
        self::assertNull($this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'X']));
    }

    public function testAPathStoppedAtItsFirstHopLeavesTheOuterNamesUntouched(): void
    {
        $user = $this->user('stopped-early@example.com');
        $ownParent = new Tag($user, 'X');
        $nested = new Tag($user, 'C');
        $nested->setParent($ownParent);
        $this->entityManager->persist($ownParent);
        $this->entityManager->persist($nested);
        $this->entityManager->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://stopped.example.com/rss.xml', 'Stopped', ['A', 'B', 'C']),
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame([], $result->tagsCreated);
        self::assertSame($ownParent, $nested->getParent());
    }

    public function testAPathThatWouldLoopStopsNesting(): void
    {
        $user = $this->user('loop@example.com');

        $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItemModel('https://ab.example.com/rss.xml', 'AB', ['A', 'B']),
            new BulkSubscribeItemModel('https://ba.example.com/rss.xml', 'BA', ['B', 'A']),
        ]);

        self::assertNull($this->storedTag($user, 'A')->getParent());
        self::assertSame('A', $this->storedTag($user, 'B')->getParent()?->getName());
    }

    /**
     * @param array<array-key, Tag> $tags
     *
     * @return list<string>
     */
    private function namesOf(array $tags): array
    {
        return array_values(array_map(static fn (Tag $tag): string => $tag->getName(), $tags));
    }

    private function storedTag(User $user, string $name): Tag
    {
        $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => $name]);
        self::assertInstanceOf(Tag::class, $tag);

        return $tag;
    }

    private function subscriptionTo(User $user, string $feedUrl): Subscription
    {
        $feed = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => $feedUrl]);
        self::assertNotNull($feed);
        $subscription = $this->entityManager
            ->getRepository(Subscription::class)
            ->findOneBy(['user' => $user, 'feed' => $feed]);
        self::assertNotNull($subscription);

        return $subscription;
    }
}
