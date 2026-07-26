<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\BulkSubscribeItem;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\TagStyle;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BulkSubscriberTest extends DbTestCase
{
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

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em(), $hasher))->create($email);
    }

    public function testSubscribesEachItemOnceAndTagsItUnderItsCategory(): void
    {
        $user = $this->user('bulk@example.com');

        $style = new TagStyle('#3b82f6', 'memory');
        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('https://a.example.com/rss.xml', 'A Feed', 'Technology', $style),
            new BulkSubscribeItem('https://b.example.com/rss.xml', 'B Feed', 'Technology', $style),
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
            new BulkSubscribeItem('https://shared.example.com/rss.xml', 'Catalog Title', null, null),
            new BulkSubscribeItem('https://fresh.example.com/rss.xml', 'Catalog Title', null, null),
        ]);

        $shared = $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://shared.example.com/rss.xml']);
        $fresh = $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://fresh.example.com/rss.xml']);

        self::assertNotNull($shared);
        self::assertNotNull($fresh);
        self::assertSame('Publisher Title', $shared->getTitle(), 'an existing shared Feed row is never retitled');
        self::assertSame('Catalog Title', $fresh->getTitle(), 'a new Feed row is seeded from the catalog');
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
            new BulkSubscribeItem(
                'https://c.example.com/rss.xml',
                'C Feed',
                'Technology',
                new TagStyle('#3b82f6', 'memory'),
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
            new BulkSubscribeItem('https://d.example.com/rss.xml', 'D Feed', null, null),
            new BulkSubscribeItem('https://d.example.com/rss.xml', 'D Feed', null, null),
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
        self::assertCount(1, $this->em()->getRepository(Subscription::class)->findAll());
    }

    public function testRejectsAnUnusableUrlWithoutAbortingTheBatch(): void
    {
        $user = $this->user('invalid@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('not-a-url', 'Bad', null, null),
            new BulkSubscribeItem('https://e.example.com/rss.xml', 'E Feed', null, null),
        ]);

        self::assertSame(1, $result->invalid);
        self::assertSame(1, $result->imported);
    }
}
