<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\EntryStateRepository;
use App\Repository\EntryTwinRepository;
use App\Repository\RefusedProfileSubscriptionRepository;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialLikeRepository;
use App\Service\SavedPosts\SavedStateMover;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfileMove;
use App\Service\Social\HiddenProfiles\CachedHiddenProfiles;
use App\Service\Social\HiddenProfileSwitchCursor;
use App\Service\Social\HiddenProfileSwitchSweep;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\HiddenProfileMoveOutcome;
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\PrivateFeeds;
use App\Service\Social\ProfileVisibilityCache;
use App\Service\Subscription\SubscriptionService;
use App\Tests\DbTestCase;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubHiddenProfiles;
use App\Tests\Support\UserFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HiddenProfileSwitchSweepTest extends DbTestCase
{
    private const string CAROL = 'did:plc:carol';
    private const string NOW = '2026-10-17 12:00:00';
    private const string POST = 'at://did:plc:hczfd45prvqtdrw77256syag/app.bsky.feed.post/3abc';
    private const string RUSTY_OLDER_POST = 'at://did:plc:hczfd45prvqtdrw77256syag/app.bsky.feed.post/2zzz';

    private StubHiddenProfiles $hiddenProfiles;
    private ProfileVisibilityCache $profileVisibility;
    private HiddenProfileSwitchCursor $cursor;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->hiddenProfiles->hide(new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty'));
        $this->profileVisibility = new ProfileVisibilityCache(new ArrayAdapter());
        $this->cursor = new HiddenProfileSwitchCursor(new ArrayAdapter());
        $this->logger = new RecordingLogger();
    }

    public function testTheFirstSweepGivesTheSubscriberAnAuthorFeedWithTheSameSettings(): void
    {
        $user = $this->user('switch-start@example.test');
        $account = $this->accountOf($user);
        $rss = $this->rssSubscription($user);
        $rss->setCustomTitle('Rusty, old');
        $rss->setPosition(7);
        $rss->setIncludeInForYou(false);
        $tag = new Tag($user, 'People');
        $tag->setPosition(0);
        $this->entityManager->persist($tag);
        $rss->addTag($tag, 3);
        $this->entityManager->flush();

        $report = $this->sweep(1);

        self::assertEquals(new HiddenProfileSwitchReportModel(1, 0), $report);
        $this->entityManager->clear();
        $author = $this->authorSubscriptionOf($account);
        self::assertSame('Rusty, old', $author?->getCustomTitle());
        self::assertSame(7, $author->getPosition());
        self::assertFalse($author->isIncludeInForYou());
        self::assertTrue($author->isIncludeInAllItems());
        self::assertSame(
            ['People'],
            array_map(static fn (Tag $each): string => $each->getName(), $author->getTags()->toArray()),
        );
        self::assertNotNull($this->entityManager->find(Subscription::class, $rss->requireId()));
    }

    public function testOnceTheAuthorFeedFetchedTheSavedStateMovesAndTheRssSubscriptionGoes(): void
    {
        $user = $this->user('switch-finish@example.test');
        $account = $this->accountOf($user);
        $rss = $this->rssSubscription($user);
        $rssId = $rss->requireId();
        $rssFeedId = $rss->getFeed()->requireId();
        $saved = new EntryState($user, $this->entry($rss->getFeed()));
        $saved->markFavorite();
        $saved->hide(new \DateTimeImmutable(self::NOW));
        $this->entityManager->persist($saved);
        $this->entityManager->flush();
        $this->sweep(1);
        $authorFeed = $this->authorSubscriptionOf($account)?->getFeed();
        self::assertNotNull($authorFeed);
        $twin = $this->entry($authorFeed);
        $authorFeed->recordSuccessfulFetch(new \DateTimeImmutable(self::NOW), 15);
        $this->entityManager->flush();

        $report = $this->sweep(1);

        self::assertEquals(new HiddenProfileSwitchReportModel(0, 1), $report);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Subscription::class, $rssId));
        self::assertNull($this->entityManager->find(Feed::class, $rssFeedId));
        $states = $this->entityManager->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $states);
        $moved = $states->findOneForUserEntry($user->requireId(), $twin->requireId());
        self::assertTrue($moved?->isFavorite());
        self::assertTrue($moved->isHidden());
    }

    public function testAFavouriteWithoutATwinInTheAuthorFeedEndsUpInTheSavedFeed(): void
    {
        $user = $this->user('switch-unmatched@example.test');
        $account = $this->accountOf($user);
        $rss = $this->rssSubscription($user);
        $at = new \DateTimeImmutable(self::NOW);
        $older = new Entry($rss->getFeed(), self::RUSTY_OLDER_POST, null, 'Older than the first page', $at, $at);
        $this->entityManager->persist($older);
        $favourite = new EntryState($user, $older);
        $favourite->markFavorite();
        $this->entityManager->persist($favourite);
        $this->entityManager->flush();
        $this->sweep(1);
        $authorFeed = $this->authorSubscriptionOf($account)?->getFeed();
        self::assertNotNull($authorFeed);
        $this->entry($authorFeed);
        $authorFeed->recordSuccessfulFetch(new \DateTimeImmutable(self::NOW), 15);
        $this->entityManager->flush();

        self::assertEquals(new HiddenProfileSwitchReportModel(0, 1), $this->sweep(1));

        $this->entityManager->clear();
        $savedFeed = $this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => SavedFeedUrl::of($user->requireId(), SocialNetwork::Bluesky),
        ]);
        self::assertNotNull($savedFeed);
        $saved = $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $savedFeed]);
        self::assertSame(
            ['Older than the first page'],
            array_map(static fn (Entry $entry): string => $entry->getTitle(), $saved),
        );
        $states = $this->entityManager->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $states);
        self::assertTrue($states->findOneForUserEntry($user->requireId(), $saved[0]->requireId())?->isFavorite());
    }

    public function testAStartLooksForTheSubscribersAuthorFeedOnce(): void
    {
        $user = $this->user('switch-start-lookup@example.test');
        $this->accountOf($user);
        $this->rssSubscription($user);
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->sweep(1);

        self::assertCount(1, $recorder->queriesMatching('INNER JOIN social_account'));
    }

    public function testAnAuthorFeedNotFetchedYetIsWaitedFor(): void
    {
        $user = $this->user('switch-wait@example.test');
        $this->accountOf($user);
        $rss = $this->rssSubscription($user);
        $this->sweep(1);

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(1));
        self::assertNotNull($this->entityManager->find(Subscription::class, $rss->requireId()));
        self::assertSame([[BlueskyProfiles::RUSTY]], $this->hiddenProfiles->asked);
    }

    public function testHiddenProfilesOnEitherSideOfAFullPageOfPublicOnesAllSwitch(): void
    {
        $this->hiddenProfiles->hide(new HiddenProfileModel(self::CAROL, 'Carol'));
        $user = $this->user('switch-behind-public@example.test');
        $user->setMaxSubscriptions(300);
        $account = $this->accountOf($user);
        $this->persistRssSubscription($user, self::CAROL);
        $publicActors = array_map(static fn (int $index): string => 'did:plc:public' . $index, range(1, 201));
        foreach ($publicActors as $actor) {
            $this->persistRssSubscription($user, $actor);
        }
        $this->rssSubscription($user);
        $this->profileVisibility->remember($publicActors, []);

        self::assertEquals(new HiddenProfileSwitchReportModel(2, 0), $this->sweep(1));
        self::assertNotNull($this->authorSubscriptionOf($account));
        self::assertSame([[self::CAROL, BlueskyProfiles::RUSTY]], $this->hiddenProfiles->asked);
    }

    public function testAFeedNamingNoActorIsNeverAsked(): void
    {
        $user = $this->user('switch-no-actor@example.test');
        $this->accountOf($user);
        $this->rssSubscription($user, 'not_an_actor!');
        $this->rssSubscription($user);

        $this->sweep(1);

        self::assertSame([[BlueskyProfiles::RUSTY]], $this->hiddenProfiles->asked);
    }

    public function testAnAuthorFeedReadThroughAnotherAccountIsTheOneTheSwitchFinishesOnto(): void
    {
        $user = $this->user('switch-other-account@example.test');
        $default = $this->accountOf($user);
        $default->becomeDefault();
        $imported = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::secondBlueskyOf($user));
        $authorFeed = self::service(PrivateFeeds::class)
            ->subscribe($imported, PrivateFeedModel::author(BlueskyProfiles::RUSTY, 'Rusty'), [])
            ->getFeed();
        $authorFeed->recordSuccessfulFetch(new \DateTimeImmutable(self::NOW), 15);
        $rssId = $this->rssSubscription($user)->requireId();

        self::assertEquals(new HiddenProfileSwitchReportModel(0, 1), $this->sweep(1));
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Subscription::class, $rssId));
        self::assertNull($this->authorSubscriptionOf($default));
        self::assertSame(1, $this->entityManager->getRepository(SocialFeed::class)->count([]));
    }

    public function testASubscriberWithoutAnAccountKeepsTheRefusedFeed(): void
    {
        $this->rssSubscription($this->user('switch-no-account@example.test'));

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(1));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAProfileFoundPublicIsNotAskedAgain(): void
    {
        $user = $this->user('switch-public@example.test');
        $this->accountOf($user);
        $this->rssSubscription($user, 'did:plc:public');

        $this->sweep(1);
        $this->sweep(1);

        self::assertSame([['did:plc:public']], $this->hiddenProfiles->asked);
    }

    public function testAnUnansweredCheckMovesNothingAndIsAskedAgain(): void
    {
        $user = $this->user('switch-unanswered@example.test');
        $this->accountOf($user);
        $this->rssSubscription($user);
        $failure = new SocialNetworkUnreachableException('down');
        $this->hiddenProfiles->failure = $failure;

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(1));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
        self::assertSame($failure, $this->logger->records[0]['context']['exception'] ?? null);

        $this->hiddenProfiles->failure = null;

        self::assertEquals(new HiddenProfileSwitchReportModel(1, 0), $this->sweep(1));
    }

    public function testAnEmptyBudgetAsksAndMovesNothing(): void
    {
        $user = $this->user('switch-budget@example.test');
        $this->accountOf($user);
        $this->rssSubscription($user);
        $rusty = new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty');
        $this->profileVisibility->remember([BlueskyProfiles::RUSTY], [BlueskyProfiles::RUSTY => $rusty]);

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(0));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAFullAccountKeepsItsRssFeedAndTheSkipIsLogged(): void
    {
        $user = $this->user('switch-full@example.test');
        $user->setMaxSubscriptions(1);
        $account = $this->accountOf($user);
        $this->rssSubscription($user);

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(1));
        self::assertNull($this->authorSubscriptionOf($account));
        self::assertSame('info', $this->logger->records[0]['level'] ?? null);
    }

    public function testAPageNamingACallsWorthOfUnknownProfilesIsTheLastOneRead(): void
    {
        $user = $this->user('switch-enough-unknown@example.test');
        $user->setMaxSubscriptions(300);
        $this->accountOf($user);
        $unknownActors = array_map(
            static fn (int $index): string => sprintf('did:plc:unknown%03d', $index),
            range(1, 25),
        );
        $publicActors = array_map(
            static fn (int $index): string => sprintf('did:plc:public%03d', $index),
            range(1, 176),
        );
        foreach ([...$unknownActors, ...$publicActors] as $actor) {
            $this->persistRssSubscription($user, $actor);
        }
        $this->entityManager->flush();
        $this->profileVisibility->remember($publicActors, []);
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->sweep(1);

        self::assertCount(1, $recorder->queriesMatching(' LIKE '));
        self::assertSame([$unknownActors], $this->hiddenProfiles->asked);
    }

    public function testOneCallAsksForAPageOfActorsAtMost(): void
    {
        $user = $this->user('switch-one-call@example.test');
        $this->accountOf($user);
        $actors = array_map(static fn (int $index): string => sprintf('did:plc:actor%02d', $index), range(1, 26));
        foreach ($actors as $actor) {
            $this->persistRssSubscription($user, $actor);
        }
        $this->entityManager->flush();

        $this->sweep(1);

        self::assertSame([\array_slice($actors, 0, 25)], $this->hiddenProfiles->asked);
    }

    public function testASubscriberAlreadyOnTheAuthorUrlIsSkippedAndTheNextOneStillMoves(): void
    {
        $stuck = $this->user('switch-stuck@example.test');
        $stuckAccount = $this->accountOf($stuck);
        $orphan = new Feed(sprintf('social:bluesky:%d:author:%s', $stuckAccount->requireId(), BlueskyProfiles::RUSTY));
        $this->entityManager->persist($orphan);
        $this->entityManager->persist(new Subscription($stuck, $orphan, new \DateTimeImmutable(self::NOW)));
        $rss = $this->rssSubscription($stuck);
        $next = $this->user('switch-next@example.test');
        $nextAccount = $this->accountOf($next);
        $this->entityManager->persist(new Subscription($next, $rss->getFeed(), new \DateTimeImmutable(self::NOW)));
        $this->entityManager->flush();

        self::assertEquals(new HiddenProfileSwitchReportModel(1, 0), $this->sweep(1));
        self::assertSame([[BlueskyProfiles::RUSTY]], $this->hiddenProfiles->asked);
        self::assertNotNull($this->authorSubscriptionOf($nextAccount));
        self::assertSame('info', $this->logger->records[0]['level'] ?? null);
        self::assertSame($rss->requireId(), $this->logger->records[0]['context']['subscriptionId'] ?? null);
    }

    public function testAHiddenProfileBehindThePagesOneRunReadsIsReachedByTheNextRun(): void
    {
        $user = $this->user('switch-page-cap@example.test');
        $user->setMaxSubscriptions(700);
        $account = $this->accountOf($user);
        $publicActors = array_map(static fn (int $index): string => 'did:plc:public' . $index, range(1, 600));
        foreach ($publicActors as $actor) {
            $this->persistRssSubscription($user, $actor);
        }
        $this->rssSubscription($user);
        $this->profileVisibility->remember($publicActors, []);

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(1));
        self::assertNull($this->authorSubscriptionOf($account));
        self::assertEquals(new HiddenProfileSwitchReportModel(1, 0), $this->sweep(1));
    }

    public function testARunThatReadsTheLastRefusedFeedSendsTheNextRunBackToTheFirst(): void
    {
        $user = $this->user('switch-wrap@example.test');
        $this->accountOf($user);
        $actors = array_map(static fn (int $index): string => sprintf('did:plc:actor%02d', $index), range(1, 26));
        foreach ($actors as $actor) {
            $this->persistRssSubscription($user, $actor);
        }
        $this->entityManager->flush();
        $this->sweep(1);
        $this->sweep(1);
        $this->profileVisibility = new ProfileVisibilityCache(new ArrayAdapter());

        $this->sweep(1);

        self::assertSame(
            [\array_slice($actors, 0, 25), [$actors[25]], \array_slice($actors, 0, 25)],
            $this->hiddenProfiles->asked,
        );
    }

    public function testAnUnansweredRunLeavesTheNextRunWhereItBegan(): void
    {
        $user = $this->user('switch-unanswered-cursor@example.test');
        $this->accountOf($user);
        $actors = array_map(static fn (int $index): string => sprintf('did:plc:actor%02d', $index), range(1, 26));
        foreach ($actors as $actor) {
            $this->persistRssSubscription($user, $actor);
        }
        $this->entityManager->flush();
        $this->hiddenProfiles->failure = new SocialNetworkUnreachableException('down');
        $this->sweep(1);
        $this->hiddenProfiles->failure = null;

        $this->sweep(1);

        self::assertSame([\array_slice($actors, 0, 25), \array_slice($actors, 0, 25)], $this->hiddenProfiles->asked);
    }

    public function testOneRunMovesAFewProfilesKnownHiddenAndTheNextRunTheRest(): void
    {
        $user = $this->user('switch-move-cap@example.test');
        $user->setMaxSubscriptions(100);
        $this->accountOf($user);
        $hidden = [];
        foreach (range(1, 21) as $index) {
            $did = sprintf('did:plc:hidden%02d', $index);
            $hidden[$did] = new HiddenProfileModel($did, 'Hidden ' . $index);
            $this->persistRssSubscription($user, $did);
        }
        $this->entityManager->flush();
        $this->profileVisibility->remember(array_keys($hidden), $hidden);
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        self::assertEquals(new HiddenProfileSwitchReportModel(20, 0), $this->sweep(1));
        self::assertCount(1, $recorder->queriesMatching(' LIKE '));
        self::assertEquals(new HiddenProfileSwitchReportModel(1, 0), $this->sweep(1));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAMoveForASubscriberWhoseAccountWentIsSkipped(): void
    {
        $rss = $this->rssSubscription($this->user('switch-account-gone@example.test'));

        self::assertSame(
            HiddenProfileMoveOutcome::Skipped,
            $this->move()->move($rss, new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty')),
        );
    }

    private function sweep(int $calls): HiddenProfileSwitchReportModel
    {
        return (new HiddenProfileSwitchSweep(
            self::service(RefusedProfileSubscriptionRepository::class),
            new CachedHiddenProfiles($this->hiddenProfiles, $this->profileVisibility),
            $this->profileVisibility,
            $this->cursor,
            $this->move(),
            $this->logger,
        ))->sweep(ProfileCallBudgetModel::calls($calls));
    }

    private function move(): HiddenProfileMove
    {
        return new HiddenProfileMove(
            self::service(SocialAccountRepository::class),
            self::service(PrivateFeeds::class),
            self::service(EntryTwinRepository::class),
            new SavedStateMover(
                self::service(EntryStateRepository::class),
                self::service(SocialLikeRepository::class),
                $this->entityManager,
            ),
            self::service(SubscriptionService::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function rssSubscription(User $user, string $did = BlueskyProfiles::RUSTY): Subscription
    {
        $subscription = $this->persistRssSubscription($user, $did);
        $this->entityManager->flush();

        return $subscription;
    }

    private function persistRssSubscription(User $user, string $did): Subscription
    {
        $now = new \DateTimeImmutable(self::NOW);
        $url = 'https://bsky.app/profile/' . $did . '/rss';
        $feed = new Feed($url);
        $feed->recordFailedFetch($now, $url . ': HTTP 403', 60);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, $now);
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function entry(Feed $feed): Entry
    {
        $at = new \DateTimeImmutable(self::NOW);
        $entry = new Entry($feed, self::POST, null, 'Post', $at, $at);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function authorSubscriptionOf(SocialAccount $account): ?Subscription
    {
        $feed = $this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => sprintf('social:bluesky:%d:author:%s', $account->requireId(), BlueskyProfiles::RUSTY),
        ]);

        return null === $feed
            ? null
            : $this->entityManager->getRepository(Subscription::class)->findOneBy(['feed' => $feed]);
    }

    private function accountOf(User $user): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($user));
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->entityManager, $hasher))->create($email);
    }
}
