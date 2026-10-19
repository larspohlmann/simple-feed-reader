<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\KnownSocialCollection;
use App\Entity\Preferences;
use App\Entity\PrivateFeedSyncChange;
use App\Entity\ProfileRun;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\SavedSearch;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\PrivateFeedSyncReason;
use App\Enum\ProfileRunTrigger;
use App\Enum\RecommendationBatchSize;
use App\Enum\SocialFeedKind;
use App\Service\Account\AccountReset;
use App\Service\Social\PrivateFeedSync;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;

final class AccountResetTest extends DbTestCase
{
    use SeedsUsers;

    private const string LAST_SYNCED_AT = '2026-10-17 11:00:00';

    private function reset(): AccountReset
    {
        $service = self::getContainer()->get(AccountReset::class);
        self::assertInstanceOf(AccountReset::class, $service);

        return $service;
    }

    /** @return array{0: User, 1: Feed, 2: Entry, 3: RecommendationRun, 4: ProfileRun} */
    private function seedAccount(string $email): array
    {
        $user = $this->user($email);
        $feed = new Feed('https://reset.example/' . $email);
        $this->entityManager->persist($feed);
        $tag = new Tag($user, 'Mine');
        $this->entityManager->persist($tag);
        $this->entityManager->persist(new SavedSearch($user, 'mine', false));
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->addTag($tag);
        $this->entityManager->persist($subscription);
        $entry = new Entry(
            $feed,
            'g-' . $email,
            null,
            'A',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new EntryState($user, $entry));
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: 'be nice',
            favoritesCap: 1,
            poolLimits: new RecommendationPoolLimits(10, 7, 3),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
        ));
        $this->entityManager->persist($settings);
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-05T00:00:00Z'));
        $this->entityManager->persist($run);
        $this->entityManager->persist(new RecommendationItem($run, $entry, 0, 'because'));
        $this->entityManager->persist(RecommendationRunLog::forRun(
            $run,
            CallPhase::Batch,
            0,
            1,
            '{}',
            new \DateTimeImmutable('2026-08-05T00:00:00Z'),
        ));
        $profileRun = new ProfileRun(
            $user,
            ProfileRunTrigger::Scheduled,
            new \DateTimeImmutable('2026-08-05T00:00:00Z'),
        );
        $this->entityManager->persist($profileRun);
        $this->entityManager->persist(
            RecommendationRunLog::forProfileRun($profileRun, 1, '{}', new \DateTimeImmutable('2026-08-05T00:00:00Z')),
        );
        $this->entityManager->flush();

        return [$user, $feed, $entry, $run, $profileRun];
    }

    private function seedSyncedAccount(string $email): SocialAccount
    {
        $account = SocialAccounts::blueskyOf($this->user($email));
        $this->entityManager->persist($account);
        $account->recordSync(new \DateTimeImmutable(self::LAST_SYNCED_AT), PrivateFeedSync::SYNCED_KINDS);
        $listId = 'at://did:plc:alice/app.bsky.graph.list/news';
        $this->entityManager->persist(new KnownSocialCollection($account, SocialFeedKind::UserList, $listId));
        $this->entityManager->persist(new PrivateFeedSyncChange(
            $account,
            SocialFeedKind::UserList,
            $listId,
            'News',
            PrivateFeedSyncReason::Saved,
            new \DateTimeImmutable(self::LAST_SYNCED_AT),
        ));
        $this->entityManager->flush();

        return $account;
    }

    public function testForgetsWhatThePrivateFeedSyncRememberedButKeepsWhenItLastCompleted(): void
    {
        $account = $this->seedSyncedAccount('reset-sync-memory@example.com');
        $bystander = $this->seedSyncedAccount('reset-sync-bystander@example.com');
        $accountId = $account->requireId();
        $bystanderId = $bystander->requireId();

        $this->reset()->reset($account->getUser());

        $this->entityManager->clear();
        $known = $this->entityManager->getRepository(KnownSocialCollection::class);
        $changes = $this->entityManager->getRepository(PrivateFeedSyncChange::class);
        self::assertSame([], $known->findBy(['socialAccount' => $accountId]));
        self::assertSame([], $changes->findBy(['socialAccount' => $accountId]));
        self::assertCount(1, $known->findBy(['socialAccount' => $bystanderId]));
        self::assertCount(1, $changes->findBy(['socialAccount' => $bystanderId]));
        $kept = $this->entityManager->find(SocialAccount::class, $accountId);
        self::assertInstanceOf(SocialAccount::class, $kept);
        self::assertSame(self::LAST_SYNCED_AT, $kept->getLastSyncedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(PrivateFeedSync::SYNCED_KINDS, $kept->getSyncedKinds());
    }

    public function testWipesEverythingTheUserOwns(): void
    {
        [$user, , , $run, $profileRun] = $this->seedAccount('reset-wipes@example.com');
        $userId = $user->requireId();
        $runId = $run->requireId();
        $profileRunId = $profileRun->requireId();

        $this->reset()->reset($user);

        // Bulk DQL bypasses the identity map: clear before every "is gone" assertion, or find() serves the stale row.
        $this->entityManager->clear();
        self::assertSame([], $this->entityManager->getRepository(Subscription::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->entityManager->getRepository(Tag::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->entityManager->getRepository(SavedSearch::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->entityManager->getRepository(EntryState::class)->findBy(['user' => $userId]));
        self::assertSame(
            [],
            $this->entityManager->getRepository(RecommendationRun::class)->findBy(['user' => $userId]),
        );
        self::assertSame(
            [],
            $this->entityManager->getRepository(RecommendationSettings::class)->findBy(['user' => $userId]),
        );
        self::assertSame([], $this->entityManager->getRepository(RecommendationItem::class)->findBy(['run' => $runId]));
        self::assertSame(
            [],
            $this->entityManager->getRepository(RecommendationRunLog::class)->findBy(['run' => $runId]),
        );
        self::assertSame([], $this->entityManager->getRepository(ProfileRun::class)->findBy(['user' => $userId]));
        self::assertSame(
            [],
            $this->entityManager->getRepository(RecommendationRunLog::class)->findBy(['profileRun' => $profileRunId]),
        );
        $subscriptionTags = $this->entityManager->getRepository(SubscriptionTag::class)->findAll();
        self::assertSame([], $subscriptionTags);
        $preferences = $this->entityManager->getRepository(Preferences::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(Preferences::class, $preferences);
        self::assertFalse($preferences->isScrapeFallbackEnabled());
    }

    public function testAWipedSubscriptionIsGoneWhenLookedUpByIdStraightAfterTheReset(): void
    {
        [$user] = $this->seedAccount('reset-identity-map@example.com');
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscriptionId = $subscription->requireId();

        $this->reset()->reset($user);

        self::assertNull($this->entityManager->find(Subscription::class, $subscriptionId));
    }

    public function testLeavesTheAccountRowAndSharedRowsAlone(): void
    {
        [$user, $feed, $entry] = $this->seedAccount('reset-keeps@example.com');
        $userId = $user->requireId();
        $feedId = $feed->requireId();
        $entryId = $entry->requireId();

        $this->reset()->reset($user);

        $this->entityManager->clear();
        $kept = $this->entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $kept);
        self::assertSame('reset-keeps@example.com', $kept->getEmail());
        self::assertInstanceOf(Feed::class, $this->entityManager->find(Feed::class, $feedId));
        self::assertInstanceOf(Entry::class, $this->entityManager->find(Entry::class, $entryId));
    }

    public function testDoesNotTouchAnotherUsersRows(): void
    {
        [$victim] = $this->seedAccount('reset-target@example.com');
        [, , , $bystanderRun, $bystanderProfileRun] = $this->seedAccount('reset-bystander@example.com');
        $bystander = $bystanderRun->getUser();
        $bystanderId = $bystander->requireId();
        $bystanderRunId = $bystanderRun->requireId();
        $bystanderProfileRunId = $bystanderProfileRun->requireId();

        $this->reset()->reset($victim);

        $this->entityManager->clear();
        $bystanderSubscriptions = $this->entityManager
            ->getRepository(Subscription::class)
            ->findBy(['user' => $bystanderId]);
        self::assertCount(1, $bystanderSubscriptions);
        self::assertCount(1, $this->entityManager->getRepository(Tag::class)->findBy(['user' => $bystanderId]));
        self::assertCount(1, $this->entityManager->getRepository(SavedSearch::class)->findBy(['user' => $bystanderId]));
        self::assertCount(1, $this->entityManager->getRepository(EntryState::class)->findBy(['user' => $bystanderId]));
        // The one place an over-broad cascade from the victim's wipe would
        // surface: the bystander's own subscription/tag join row.
        self::assertCount(
            1,
            $this->entityManager
                ->getRepository(SubscriptionTag::class)
                ->findBy(['subscription' => $bystanderSubscriptions[0]]),
        );
        // The subquery must correlate by the right user; unlike the "gone" side, no run-delete cascade can mask this.
        self::assertCount(
            1,
            $this->entityManager->getRepository(RecommendationItem::class)->findBy(['run' => $bystanderRunId]),
        );
        self::assertCount(
            1,
            $this->entityManager->getRepository(RecommendationRunLog::class)->findBy(['run' => $bystanderRunId]),
        );
        self::assertCount(1, $this->entityManager->getRepository(ProfileRun::class)->findBy(['user' => $bystanderId]));
        self::assertCount(
            1,
            $this->entityManager
                ->getRepository(RecommendationRunLog::class)
                ->findBy(['profileRun' => $bystanderProfileRunId]),
        );
    }

    public function testASecondResetIsANoOp(): void
    {
        [$user] = $this->seedAccount('reset-idempotent@example.com');
        $userId = $user->requireId();

        $this->reset()->reset($user);
        $freshUser = $this->entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $freshUser);
        $this->reset()->reset($freshUser);

        $this->entityManager->clear();
        self::assertInstanceOf(User::class, $this->entityManager->find(User::class, $userId));
        self::assertSame([], $this->entityManager->getRepository(Subscription::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->entityManager->getRepository(Tag::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->entityManager->getRepository(SavedSearch::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->entityManager->getRepository(EntryState::class)->findBy(['user' => $userId]));
        self::assertSame(
            [],
            $this->entityManager->getRepository(RecommendationRun::class)->findBy(['user' => $userId]),
        );
        self::assertSame(
            [],
            $this->entityManager->getRepository(RecommendationSettings::class)->findBy(['user' => $userId]),
        );
    }
}
