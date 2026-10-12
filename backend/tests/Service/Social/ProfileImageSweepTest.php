<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\ProfileImageSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\RecordingLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ProfileImageSweepTest extends DbTestCase
{
    private const string GET_PROFILES = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfiles';
    private const string NOW = '2026-10-12 12:00:00';

    private StubFeedFetcher $fetcher;
    private MockClock $clock;
    private ?HostThrottle $throttle = null;
    private ?User $subscriber = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = new StubFeedFetcher();
        $this->clock = new MockClock(self::NOW, 'UTC');
    }

    public function testItStoresEachProfilesAvatarAndStampsTheCheck(): void
    {
        $alice = $this->profileFeed('alice.bsky.social');
        $bob = $this->profileFeed('did:plc:bob');
        $this->answer(['alice.bsky.social', 'did:plc:bob'], [
            ['did' => 'did:plc:alice', 'handle' => 'Alice.bsky.social', 'avatar' => 'https://cdn.example/alice.jpg'],
            ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social', 'avatar' => 'https://cdn.example/bob.jpg'],
        ]);

        $report = $this->sweep(1);

        self::assertSame('https://cdn.example/alice.jpg', $alice->getImageUrl());
        self::assertSame('https://cdn.example/bob.jpg', $bob->getImageUrl());
        self::assertSame(self::NOW, $alice->getImageCheckedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(2, $report->checked);
        self::assertSame(2, $report->changed);
    }

    public function testItAsksForAtMostAPageOfActorsPerCall(): void
    {
        $handles = array_map(static fn (int $index): string => sprintf('user%02d.bsky.social', $index), range(1, 26));
        foreach ($handles as $handle) {
            $this->profileFeed($handle);
        }
        $this->answer(\array_slice($handles, 0, 25), []);
        $this->answer(\array_slice($handles, 25), []);

        $this->sweep(2);

        self::assertCount(2, $this->fetcher->fetchedUrls);
    }

    public function testTheBudgetCapsTheCalls(): void
    {
        $handles = array_map(static fn (int $index): string => sprintf('user%02d.bsky.social', $index), range(1, 26));
        $feeds = array_map($this->profileFeed(...), $handles);
        $this->answer(\array_slice($handles, 0, 25), []);

        $report = $this->sweep(1);

        self::assertCount(1, $this->fetcher->fetchedUrls);
        self::assertSame(25, $report->checked);
        self::assertNull($feeds[25]->getImageCheckedAt());
    }

    public function testAnEmptyBudgetAsksNothing(): void
    {
        $this->profileFeed('alice.bsky.social');

        $this->sweep(0);

        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAPictureCheckedWithinTheWeekIsNotAskedForAgain(): void
    {
        $fresh = $this->profileFeed('fresh.bsky.social', '2026-10-06 12:00:00');
        $stale = $this->profileFeed('stale.bsky.social', '2026-10-04 12:00:00');
        $this->answer(['stale.bsky.social'], [
            ['did' => 'did:plc:stale', 'handle' => 'stale.bsky.social', 'avatar' => 'https://cdn.example/new.jpg'],
        ]);

        $this->sweep(1);

        self::assertSame('2026-10-06 12:00:00', $fresh->getImageCheckedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('https://cdn.example/new.jpg', $stale->getImageUrl());
    }

    public function testAnAvatarThatIsNoWebUrlOrTooLongIsStoredAsNone(): void
    {
        $script = $this->profileFeed('script.bsky.social', null, 'https://cdn.example/old.jpg');
        $long = $this->profileFeed('long.bsky.social', null, 'https://cdn.example/old.jpg');
        $this->answer(['script.bsky.social', 'long.bsky.social'], [
            ['did' => 'did:plc:script', 'handle' => 'script.bsky.social', 'avatar' => 'javascript:alert(1)'],
            [
                'did' => 'did:plc:long',
                'handle' => 'long.bsky.social',
                'avatar' => 'https://cdn.example/' . str_repeat('a', 2048),
            ],
        ]);

        $this->sweep(1);

        self::assertNull($script->getImageUrl());
        self::assertNull($long->getImageUrl());
    }

    public function testAProfileThatRemovedItsAvatarLosesThePicture(): void
    {
        $feed = $this->profileFeed('plain.bsky.social', null, 'https://cdn.example/old.jpg');
        $this->answer(['plain.bsky.social'], [['did' => 'did:plc:plain', 'handle' => 'plain.bsky.social']]);

        $this->sweep(1);

        self::assertNull($feed->getImageUrl());
    }

    public function testAnUnknownProfileKeepsItsPictureButIsStamped(): void
    {
        $feed = $this->profileFeed('gone.bsky.social', null, 'https://cdn.example/old.jpg');
        $this->answer(['gone.bsky.social'], []);

        $this->sweep(1);

        self::assertSame('https://cdn.example/old.jpg', $feed->getImageUrl());
        self::assertNotNull($feed->getImageCheckedAt());
    }

    public function testAnUnreachableAppViewStampsNothing(): void
    {
        $feed = $this->profileFeed('alice.bsky.social');
        $failure = new FeedUnreachableException('timeout');
        $this->fetcher->willThrow(self::GET_PROFILES . '?actors=alice.bsky.social', $failure);

        $report = $this->sweep(1);

        self::assertNull($feed->getImageCheckedAt());
        self::assertSame(0, $report->checked);
    }

    public function testAThrottledAppViewIsNotAsked(): void
    {
        $this->profileFeed('alice.bsky.social');
        $this->throttle()->record(self::GET_PROFILES, 60);

        self::assertSame(0, $this->sweep(1)->checked);
        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAnUnreachableAppViewEndsThePassAndIsLogged(): void
    {
        $handles = array_map(static fn (int $index): string => sprintf('user%02d.bsky.social', $index), range(1, 26));
        array_map($this->profileFeed(...), $handles);
        $this->fetcher->willThrowForEverythingElse(new FeedUnreachableException('timeout'));
        $logger = new RecordingLogger();

        $this->sweep(2, $logger);

        self::assertCount(1, $this->fetcher->fetchedUrls);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertInstanceOf(FeedUnreachableException::class, $logger->records[0]['context']['exception']);
    }

    public function testTheCheckIsStored(): void
    {
        $feed = $this->profileFeed('alice.bsky.social');
        $this->answer(['alice.bsky.social'], [
            ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'avatar' => 'https://cdn.example/a.jpg'],
        ]);

        $this->sweep(1);
        $this->entityManager->clear();

        $stored = $this->entityManager->find(Feed::class, $feed->requireId());
        self::assertSame('https://cdn.example/a.jpg', $stored?->getImageUrl());
    }

    public function testTwoFeedsOfOneActorAskForItOnce(): void
    {
        $first = $this->profileFeed('alice.bsky.social');
        $second = new Feed('https://bsky.app/profile/alice.bsky.social/rss/');
        $this->persistSubscribed($second);
        $this->answer(['alice.bsky.social'], [
            ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'avatar' => 'https://cdn.example/a.jpg'],
        ]);

        $this->sweep(1);

        self::assertSame('https://cdn.example/a.jpg', $first->getImageUrl());
        self::assertSame('https://cdn.example/a.jpg', $second->getImageUrl());
    }

    public function testAnUnchangedOrUnknownPictureCountsAsCheckedButNotChanged(): void
    {
        $this->profileFeed('same.bsky.social', null, 'https://cdn.example/same.jpg');
        $this->profileFeed('gone.bsky.social', null, 'https://cdn.example/gone.jpg');
        $this->answer(['same.bsky.social', 'gone.bsky.social'], [
            ['did' => 'did:plc:same', 'handle' => 'same.bsky.social', 'avatar' => 'https://cdn.example/same.jpg'],
        ]);

        $report = $this->sweep(1);

        self::assertSame(2, $report->checked);
        self::assertSame(0, $report->changed);
    }

    public function testABatchTheAppViewRefusesIsSetAsideAndTheNextBatchIsAsked(): void
    {
        $handles = array_map(static fn (int $index): string => sprintf('user%02d.bsky.social', $index), range(1, 26));
        $feeds = array_map($this->profileFeed(...), $handles);
        $refusal = new FeedUnreachableException('400', 400);
        $this->fetcher->willThrow(self::profilesUrl(\array_slice($handles, 0, 25)), $refusal);
        $this->answer(\array_slice($handles, 25), []);

        $report = $this->sweep(2);

        self::assertCount(2, $this->fetcher->fetchedUrls);
        self::assertSame(self::NOW, $feeds[0]->getImageCheckedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(self::NOW, $feeds[25]->getImageCheckedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(26, $report->checked);
    }

    public function testAMalformedAnswerSetsTheBatchAside(): void
    {
        $feed = $this->profileFeed('alice.bsky.social');
        $this->fetcher->willReturnBody(self::profilesUrl(['alice.bsky.social']), '{"posts":[]}');

        $this->sweep(1);

        self::assertNotNull($feed->getImageCheckedAt());
    }

    public function testAnActorThatIsNoHandleOrDidIsNeverAsked(): void
    {
        $odd = $this->profileFeed('some%20name');
        $alice = $this->profileFeed('alice.bsky.social');
        $this->answer(['alice.bsky.social'], [
            ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'avatar' => 'https://cdn.example/a.jpg'],
        ]);

        $this->sweep(1);

        self::assertSame([self::profilesUrl(['alice.bsky.social'])], $this->fetcher->fetchedUrls);
        self::assertNotNull($odd->getImageCheckedAt());
        self::assertSame('https://cdn.example/a.jpg', $alice->getImageUrl());
    }

    /**
     * @param list<string>                     $actors
     * @param list<array<string, string>>      $profiles
     */
    private function answer(array $actors, array $profiles): void
    {
        $this->fetcher->willReturnBody(
            self::profilesUrl($actors),
            json_encode(['profiles' => $profiles], \JSON_THROW_ON_ERROR),
        );
    }

    private function sweep(int $calls, LoggerInterface $logger = new NullLogger()): ProfileImageSweepReportModel
    {
        $feeds = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);

        $sweep = new ProfileImageSweep(
            $feeds,
            new AppViewClient($this->fetcher, $this->throttle()),
            $this->entityManager,
            $this->clock,
            $logger,
        );

        return $sweep->sweep(ProfileImageBudgetModel::calls($calls));
    }

    /** @param list<string> $actors */
    private static function profilesUrl(array $actors): string
    {
        $pairs = array_map(static fn (string $actor): string => 'actors=' . rawurlencode($actor), $actors);

        return self::GET_PROFILES . '?' . implode('&', $pairs);
    }

    public function testAFeedNobodySubscribesToIsNeverAsked(): void
    {
        $orphan = new Feed('https://bsky.app/profile/orphan.bsky.social/rss');
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        $this->sweep(1);

        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    private function persistSubscribed(Feed $feed): void
    {
        $this->subscriber ??= new User('profile-image-sweep@example.com', $this->clock->now());
        $this->entityManager->persist($this->subscriber);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->subscriber, $feed, $this->clock->now()));
        $this->entityManager->flush();
    }

    private function throttle(): HostThrottle
    {
        return $this->throttle ??= new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
    }

    private function profileFeed(string $actor, ?string $checkedAt = null, ?string $imageUrl = null): Feed
    {
        $feed = new Feed('https://bsky.app/profile/' . $actor . '/rss');
        $feed->setImageUrl($imageUrl);
        if (null !== $checkedAt) {
            $feed->markImageCheckedAt(new \DateTimeImmutable($checkedAt));
        }
        $this->persistSubscribed($feed);

        return $feed;
    }
}
