<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Preferences;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\RecommendationBatchSize;
use App\Service\Account\AccountReset;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class AccountResetTest extends DbTestCase
{
    use SeedsUsers;

    private function reset(): AccountReset
    {
        $service = self::getContainer()->get(AccountReset::class);
        self::assertInstanceOf(AccountReset::class, $service);

        return $service;
    }

    /**
     * Seeds one full account and returns [user, feed, entry, run].
     *
     * @return array{0: User, 1: Feed, 2: Entry, 3: RecommendationRun}
     */
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
            historyCaps: new RecommendationHistoryCaps(1, 1, 1),
            poolLimits: new RecommendationPoolLimits(10, 7, 3),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
        ));
        $this->entityManager->persist($settings);
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-05T00:00:00Z'));
        $this->entityManager->persist($run);
        $this->entityManager->persist(new RecommendationItem($run, $entry, 0, 'because'));
        $this->entityManager->persist(new RecommendationRunLog(
            $run,
            CallPhase::Batch,
            0,
            1,
            '{}',
            new \DateTimeImmutable('2026-08-05T00:00:00Z'),
        ));
        $this->entityManager->flush();

        return [$user, $feed, $entry, $run];
    }

    public function testWipesEverythingTheUserOwns(): void
    {
        [$user, , , $run] = $this->seedAccount('reset-wipes@example.com');
        $userId = $user->requireId();
        $runId = $run->requireId();

        $this->reset()->reset($user);

        // Bulk DQL bypasses the identity map — clear before every "is gone"
        // assertion, or find() serves the stale in-memory row (#412 spec).
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
        [, , , $bystanderRun] = $this->seedAccount('reset-bystander@example.com');
        $bystander = $bystanderRun->getUser();
        $bystanderId = $bystander->requireId();
        $bystanderRunId = $bystanderRun->requireId();

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
        // Proves the recommendation-child subquery correlates by the RIGHT
        // user: the bystander's run is untouched, so nothing here is masked
        // by the victim's own run-delete cascade — unlike the "gone" side of
        // this statement, this assertion cannot pass by accident.
        self::assertCount(
            1,
            $this->entityManager->getRepository(RecommendationItem::class)->findBy(['run' => $bystanderRunId]),
        );
        self::assertCount(
            1,
            $this->entityManager->getRepository(RecommendationRunLog::class)->findBy(['run' => $bystanderRunId]),
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
