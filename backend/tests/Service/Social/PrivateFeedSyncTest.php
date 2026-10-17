<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\KnownSocialCollection;
use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\PrivateFeedSyncReason;
use App\Enum\PrivateFeedSyncSkip;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
use App\Repository\EntryStateRepository;
use App\Repository\KnownSocialCollectionRepository;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\PrivateFeedSyncOutcomeModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\PrivateFeeds;
use App\Service\Social\PrivateFeedSync;
use App\Service\Subscription\SubscriptionService;
use App\Tests\DbTestCase;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\PrivateFeedSyncWiring;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SavedPostFixtures;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubSocialCandidates;
use App\Tests\Support\UserFactory;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PrivateFeedSyncTest extends DbTestCase
{
    private const string NOW = '2026-10-17 12:00:00';
    private const string AN_HOUR_AGO = '2026-10-17 11:00:00';
    private const string NEWS = 'at://did:plc:alice/app.bsky.graph.list/news';
    private const string ART = 'at://did:plc:bob/app.bsky.feed.generator/art';

    private MockClock $clock;
    private User $user;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock(self::NOW, 'UTC');

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('private-feed-sync@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
    }

    public function testTheFirstCheckTakesABaselineAndAddsNothing(): void
    {
        $outcome = $this->syncAgainst(self::network([self::news()]));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(), $outcome);
        self::assertSame([], $this->subscribedUrls());
        self::assertSame(['list:' . self::NEWS], $this->knownKeys());
        self::assertSame([], $this->changes());
        $this->assertCompleteCheckAt(self::NOW);
    }

    public function testANewlySavedFeedIsAddedWithImportDefaultsAndLogged(): void
    {
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(self::network([], [self::art()]));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(added: 1), $outcome);
        $subscription = $this->onlySubscription();
        self::assertSame($this->artUrl(), $subscription->getFeed()->getUrl());
        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou());
        self::assertCount(0, $subscription->getTags());
        self::assertSame(['custom_feed:' . self::ART], $this->knownKeys());
        self::assertSame([['custom_feed', self::ART, 'Art', PrivateFeedSyncReason::Saved]], $this->changes());
        $this->assertCompleteCheckAt(self::NOW);
    }

    public function testAnUnsavedFeedIsRemovedSilentlyAndItsFavouritesSurvive(): void
    {
        $guid = 'at://did:plc:carol/app.bsky.feed.post/1';
        $this->favouriteIn($this->subscribedArtFeed(), $guid);
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(self::network([]));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(removed: 1), $outcome);
        self::assertSame([sprintf('saved:%d:bluesky', $this->user->requireId())], $this->subscribedUrls());
        self::assertSame([], $this->knownKeys());
        self::assertSame([['custom_feed', self::ART, 'Art', PrivateFeedSyncReason::Unsaved]], $this->changes());
        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => sprintf('saved:%d:bluesky', $this->user->requireId()),
        ]);
        self::assertNotNull($saved);
        $copies = $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $saved, 'guid.value' => $guid]);
        self::assertCount(1, $copies);
        self::assertTrue($this->stateOf($copies[0])?->isFavorite());
    }

    public function testADroppedListIsNotAddedBack(): void
    {
        $this->privateFeeds()->create($this->account, self::newsModel());
        $this->subscriptionService()->unsubscribe($this->onlySubscription());
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(self::network([self::news()]));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(), $outcome);
        self::assertSame([], $this->subscribedUrls());
        self::assertSame(['list:' . self::NEWS], $this->knownKeys());
        self::assertSame([], $this->changes());
    }

    public function testAListTheSyncRemovedComesBackWhenSavedAgain(): void
    {
        $this->privateFeeds()->create($this->account, self::newsModel());
        $this->stampAnHourAgo();
        $this->syncAgainst(self::network([]));

        $outcome = $this->syncAgainst(self::network([self::news()]));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(added: 1), $outcome);
        self::assertCount(1, $this->subscribedUrls());
    }

    public function testHomeAndAnyKindOffTheAllowListAreNeverTouched(): void
    {
        self::assertSame([SocialFeedKind::UserList, SocialFeedKind::CustomFeed], PrivateFeedSync::SYNCED_KINDS);
        $this->privateFeeds()->create($this->account, PrivateFeedModel::home());
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(new NetworkCollectionsModel([], [], [], false));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(), $outcome);
        self::assertSame([sprintf('social:bluesky:%d:home', $this->account->requireId())], $this->subscribedUrls());
        self::assertSame([], $this->knownKeys());
        self::assertSame([], $this->changes());
    }

    public function testAnUnreachableNetworkSkipsTheAccountAndChangesNothing(): void
    {
        $this->subscribedArtFeed();
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(new SocialNetworkUnreachableException('Bluesky did not answer.'));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Unreachable), $outcome);
        self::assertSame([$this->artUrl()], $this->subscribedUrls());
        $this->assertSkippedNow(PrivateFeedSyncSkip::Unreachable);
    }

    public function testATruncatedAnswerSkipsTheAccount(): void
    {
        $this->subscribedArtFeed();
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(new NetworkCollectionsModel([self::news()], [], truncated: true));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Incomplete), $outcome);
        self::assertSame([$this->artUrl()], $this->subscribedUrls());
        self::assertSame(['custom_feed:' . self::ART], $this->knownKeys());
        self::assertSame([], $this->changes());
        $this->assertSkippedNow(PrivateFeedSyncSkip::Incomplete);
    }

    public function testCappedSavedListsSkipTheAccount(): void
    {
        $this->subscribedArtFeed();
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(new NetworkCollectionsModel([self::news()], [], savedListsCapped: true));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Incomplete), $outcome);
        self::assertSame([$this->artUrl()], $this->subscribedUrls());
        self::assertSame([], $this->changes());
    }

    public function testARefusedCredentialMarksTheAccountAndSkips(): void
    {
        $outcome = $this->syncAgainst(new SocialCredentialsRejectedException('Bluesky refused the app password.'));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::NeedsReconnect), $outcome);
        $this->entityManager->clear();
        $account = $this->reloadedAccount();
        self::assertSame(SocialAccountStatus::NeedsReconnect, $account->getStatus());
        self::assertSame(PrivateFeedSyncSkip::NeedsReconnect, $account->getLastSyncSkip());
        self::assertSame(self::NOW, $account->getLastCheckedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAnAccountThatNeedsReconnectingIsSkippedWithoutAsking(): void
    {
        $this->account->markNeedsReconnect();
        $this->entityManager->flush();
        $network = new StubSocialCandidates(self::noCandidates(), self::network([self::news()]));

        $outcome = $this->sync($network)->sync($this->account);

        self::assertSame(0, $network->calls);
        self::assertEquals(new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::NeedsReconnect), $outcome);
        self::assertSame(PrivateFeedSyncSkip::NeedsReconnect, $this->account->getLastSyncSkip());
        self::assertSame(self::NOW, $this->account->getLastCheckedAt()?->format('Y-m-d H:i:s'));
    }

    public function testTheLogKeepsTheTenNewestChanges(): void
    {
        $this->stampAnHourAgo();
        $feeds = array_map(
            static fn (int $index): SocialCollectionModel => new SocialCollectionModel(
                'at://did:plc:bob/app.bsky.feed.generator/feed-' . $index,
                'Feed ' . $index,
            ),
            range(1, 11),
        );

        $outcome = $this->syncAgainst(self::network([], $feeds));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(added: 11), $outcome);
        self::assertCount(11, $this->subscribedUrls());
        self::assertSame(
            array_map(static fn (int $index): string => 'Feed ' . $index, range(2, 11)),
            array_column($this->changes(), 2),
        );
    }

    public function testACheckThatChangesNothingLeavesTheLogAlone(): void
    {
        $this->stampAnHourAgo();
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $outcome = $this->syncAgainst(self::network([]));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(), $outcome);
        self::assertSame([], $recorder->queriesMatching('private_feed_sync_change'));
    }

    public function testAFullAccountSkipsAnAdditionAndTriesAgainNextTime(): void
    {
        $this->user->setMaxSubscriptions(0);
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(self::network([], [self::art()]));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(), $outcome);
        self::assertSame([], $this->subscribedUrls());
        self::assertSame([], $this->knownKeys());
        self::assertSame([], $this->changes());
        $this->assertCompleteCheckAt(self::NOW);
    }

    public function testAFailedFirstCheckLeavesTheBaselineToTheFirstCompleteOne(): void
    {
        $this->syncAgainst(new SocialNetworkUnreachableException('Bluesky did not answer.'));
        self::assertSame(PrivateFeedSyncSkip::Unreachable, $this->account->getLastSyncSkip());
        self::assertSame(self::NOW, $this->account->getLastCheckedAt()?->format('Y-m-d H:i:s'));
        self::assertNull($this->account->getLastSyncedAt());
        $this->clock->modify('+2 hours');

        $outcome = $this->syncAgainst(self::network([self::news()], [self::art()]));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(), $outcome);
        self::assertSame([], $this->subscribedUrls());
        self::assertSame(['custom_feed:' . self::ART, 'list:' . self::NEWS], $this->knownKeys());
        $this->assertCompleteCheckAt('2026-10-17 14:00:00');
    }

    public function testWithoutTheSavedFeedsPreferenceASubscribedCustomFeedSkipsTheAccount(): void
    {
        $this->subscribedArtFeed();
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(new NetworkCollectionsModel([], [], [], false, savedFeedsKnown: false));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Incomplete), $outcome);
        self::assertSame([$this->artUrl()], $this->subscribedUrls());
        self::assertSame([], $this->changes());
    }

    public function testWithoutTheSavedFeedsPreferenceASubscribedSavedListSkipsTheAccountToo(): void
    {
        $theirs = 'at://did:plc:bob/app.bsky.graph.list/theirs';
        $this->privateFeeds()->create($this->account, PrivateFeedModel::userList($theirs, 'Theirs'));
        $this->stampAnHourAgo();

        $outcome = $this->syncAgainst(new NetworkCollectionsModel([self::news()], [], savedFeedsKnown: false));

        self::assertEquals(new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Incomplete), $outcome);
        self::assertCount(1, $this->subscribedUrls());
        self::assertSame([], $this->changes());
    }

    public function testAnUnexpectedFailureRecordsAFailedCheckLogsItAndPersistsNoHalfDoneChange(): void
    {
        $entry = $this->favouriteIn($this->subscribedArtFeed(), 'at://did:plc:carol/app.bsky.feed.post/1');
        $this->stampAnHourAgo();
        $this->stateOf($entry)?->clearFavorite();
        $logger = new RecordingLogger();
        $network = new StubSocialCandidates(self::noCandidates(), new \LogicException('Unexpected answer shape.'));

        $container = self::getContainer();
        $sync = PrivateFeedSyncWiring::sync($container, $this->entityManager, $this->clock, $network, $logger);

        $outcome = $sync->sync($this->account);

        self::assertEquals(new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Failed), $outcome);
        $this->entityManager->clear();
        $this->assertSkippedNow(PrivateFeedSyncSkip::Failed);
        self::assertTrue($this->stateOf($entry)?->isFavorite());
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertInstanceOf(\LogicException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testAFailureAfterTheUnsubscribeRollsTheWholeCheckBack(): void
    {
        $entry = $this->favouriteIn($this->subscribedArtFeed(), 'at://did:plc:carol/app.bsky.feed.post/1');
        $this->stampAnHourAgo();
        $failing = new FlushFailingEntityManager($this->entityManager);
        $network = new StubSocialCandidates(self::noCandidates(), self::network([]));

        $outcome = PrivateFeedSyncWiring::sync(self::getContainer(), $failing, $this->clock, $network)
            ->sync($this->account);

        self::assertEquals(new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Failed), $outcome);
        $this->entityManager->clear();
        self::assertSame([$this->artUrl()], $this->subscribedUrls());
        self::assertSame(['custom_feed:' . self::ART], $this->knownKeys());
        self::assertSame([], $this->changes());
        self::assertTrue($this->stateOf($entry)?->isFavorite());
        self::assertNull($this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => sprintf('saved:%d:bluesky', $this->user->requireId()),
        ]));
        $this->assertSkippedNow(PrivateFeedSyncSkip::Failed);
    }

    private function syncAgainst(NetworkCollectionsModel|\Throwable $answer): PrivateFeedSyncOutcomeModel
    {
        return $this->sync(new StubSocialCandidates(self::noCandidates(), $answer))->sync($this->account);
    }

    private function sync(StubSocialCandidates $network): PrivateFeedSync
    {
        return PrivateFeedSyncWiring::sync(self::getContainer(), $this->entityManager, $this->clock, $network);
    }

    private function privateFeeds(): PrivateFeeds
    {
        return PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock);
    }

    private function subscriptionService(): SubscriptionService
    {
        $subscriptionService = self::getContainer()->get(SubscriptionService::class);
        self::assertInstanceOf(SubscriptionService::class, $subscriptionService);

        return $subscriptionService;
    }

    private function stampAnHourAgo(): void
    {
        $this->account->recordSync(new \DateTimeImmutable(self::AN_HOUR_AGO));
        $this->entityManager->flush();
    }

    private function subscribedArtFeed(): Feed
    {
        $this->privateFeeds()->create($this->account, PrivateFeedModel::customFeed(self::ART, 'Art'));

        return $this->onlySubscription()->getFeed();
    }

    private function favouriteIn(Feed $feed, string $guid): Entry
    {
        $entry = SavedPostFixtures::entry($this->entityManager, $feed, $guid, null);
        SavedPostFixtures::state($this->entityManager, $this->user, $entry)->markFavorite();
        $this->entityManager->flush();

        return $entry;
    }

    private function stateOf(Entry $entry): ?EntryState
    {
        /** @var EntryStateRepository $states */
        $states = $this->entityManager->getRepository(EntryState::class);

        return $states->findOneForUserEntry($this->user->requireId(), $entry->requireId());
    }

    private function onlySubscription(): Subscription
    {
        $subscriptions = $this->entityManager->getRepository(Subscription::class)->findAll();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }

    /** @return list<string> */
    private function subscribedUrls(): array
    {
        $urls = array_map(
            static fn (Subscription $subscription): string => $subscription->getFeed()->getUrl(),
            $this->entityManager->getRepository(Subscription::class)->findBy(['user' => $this->user->requireId()]),
        );
        sort($urls);

        return $urls;
    }

    /** @return list<string> */
    private function knownKeys(): array
    {
        /** @var KnownSocialCollectionRepository $known */
        $known = $this->entityManager->getRepository(KnownSocialCollection::class);
        $keys = array_keys($known->keysOf($this->reloadedAccount()));
        sort($keys);

        return $keys;
    }

    /** @return list<array{string, string, string, PrivateFeedSyncReason}> */
    private function changes(): array
    {
        return array_map(
            static fn (PrivateFeedSyncChange $change): array => [
                $change->getKind()->value,
                $change->getRemoteId(),
                $change->getName(),
                $change->getReason(),
            ],
            $this->entityManager->getRepository(PrivateFeedSyncChange::class)->findBy(
                ['socialAccount' => $this->account->requireId()],
                ['id' => 'ASC'],
            ),
        );
    }

    private function reloadedAccount(): SocialAccount
    {
        $account = $this->entityManager->find(SocialAccount::class, $this->account->requireId());
        self::assertNotNull($account);

        return $account;
    }

    private function assertCompleteCheckAt(string $at): void
    {
        $account = $this->reloadedAccount();
        self::assertSame($at, $account->getLastCheckedAt()?->format('Y-m-d H:i:s'));
        self::assertSame($at, $account->getLastSyncedAt()?->format('Y-m-d H:i:s'));
        self::assertNull($account->getLastSyncSkip());
    }

    private function assertSkippedNow(PrivateFeedSyncSkip $skip): void
    {
        $account = $this->reloadedAccount();
        self::assertSame($skip, $account->getLastSyncSkip());
        self::assertSame(self::NOW, $account->getLastCheckedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(self::AN_HOUR_AGO, $account->getLastSyncedAt()?->format('Y-m-d H:i:s'));
    }

    private function artUrl(): string
    {
        return sprintf('social:bluesky:%d:feed:%s', $this->account->requireId(), self::ART);
    }

    private static function news(): SocialCollectionModel
    {
        return new SocialCollectionModel(self::NEWS, 'News');
    }

    private static function newsModel(): PrivateFeedModel
    {
        return PrivateFeedModel::userList(self::NEWS, 'News');
    }

    private static function art(): SocialCollectionModel
    {
        return new SocialCollectionModel(self::ART, 'Art');
    }

    /**
     * @param list<SocialCollectionModel> $lists
     * @param list<SocialCollectionModel> $customFeeds
     */
    private static function network(array $lists, array $customFeeds = []): NetworkCollectionsModel
    {
        return new NetworkCollectionsModel($lists, $customFeeds);
    }

    private static function noCandidates(): NetworkCandidatesModel
    {
        return new NetworkCandidatesModel([], [], [], false);
    }
}
