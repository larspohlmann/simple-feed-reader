<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Preferences;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Account\AccountReset;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountResetTest extends DbTestCase
{
    private function makeUser(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function reset(): AccountReset
    {
        $service = self::getContainer()->get(AccountReset::class);
        self::assertInstanceOf(AccountReset::class, $service);

        return $service;
    }

    /**
     * Seeds one full account and returns [user, feed, entry].
     *
     * @return array{0: User, 1: Feed, 2: Entry}
     */
    private function seedAccount(string $email): array
    {
        $user = $this->makeUser($email);
        $feed = new Feed('https://reset.example/' . $email);
        $this->em->persist($feed);
        $tag = new Tag($user, 'Mine');
        $this->em->persist($tag);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->addTag($tag);
        $this->em->persist($subscription);
        $entry = new Entry(
            $feed,
            'g-' . $email,
            null,
            'A',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->persist(new EntryState($user, $entry));
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: 'be nice',
            favoritesCap: 1,
            keptCap: 1,
            viewedCap: 1,
            candidatePoolSize: 10,
            lookbackDays: 7,
            picksLimit: 3,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
        ));
        $this->em->persist($settings);
        $this->em->persist(new RecommendationRun($user, new \DateTimeImmutable('2026-08-05T00:00:00Z')));
        $this->em->flush();

        return [$user, $feed, $entry];
    }

    public function testWipesEverythingTheUserOwns(): void
    {
        [$user] = $this->seedAccount('reset-wipes@example.com');
        $userId = (int) $user->getId();

        $this->reset()->reset($user);

        // Bulk DQL bypasses the identity map — clear before every "is gone"
        // assertion, or find() serves the stale in-memory row (#412 spec).
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Subscription::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->em->getRepository(Tag::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->em->getRepository(EntryState::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->em->getRepository(RecommendationRun::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->em->getRepository(RecommendationSettings::class)->findBy(['user' => $userId]));
        $subscriptionTags = $this->em->getRepository(SubscriptionTag::class)->findAll();
        self::assertSame([], $subscriptionTags);
        $preferences = $this->em->getRepository(Preferences::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(Preferences::class, $preferences);
        self::assertFalse($preferences->isScrapeFallbackEnabled());
    }

    public function testLeavesTheAccountRowAndSharedRowsAlone(): void
    {
        [$user, $feed, $entry] = $this->seedAccount('reset-keeps@example.com');
        $userId = (int) $user->getId();
        $feedId = (int) $feed->getId();
        $entryId = (int) $entry->getId();

        $this->reset()->reset($user);

        $this->em->clear();
        $kept = $this->em->find(User::class, $userId);
        self::assertInstanceOf(User::class, $kept);
        self::assertSame('reset-keeps@example.com', $kept->getEmail());
        self::assertInstanceOf(Feed::class, $this->em->find(Feed::class, $feedId));
        self::assertInstanceOf(Entry::class, $this->em->find(Entry::class, $entryId));
    }

    public function testDoesNotTouchAnotherUsersRows(): void
    {
        [$victim] = $this->seedAccount('reset-target@example.com');
        [$bystander] = $this->seedAccount('reset-bystander@example.com');
        $bystanderId = (int) $bystander->getId();

        $this->reset()->reset($victim);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Subscription::class)->findBy(['user' => $bystanderId]));
        self::assertCount(1, $this->em->getRepository(Tag::class)->findBy(['user' => $bystanderId]));
        self::assertCount(1, $this->em->getRepository(EntryState::class)->findBy(['user' => $bystanderId]));
    }

    public function testASecondResetIsANoOp(): void
    {
        [$user] = $this->seedAccount('reset-idempotent@example.com');

        $this->reset()->reset($user);
        $freshUser = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $freshUser);
        $this->reset()->reset($freshUser);

        $this->em->clear();
        self::assertInstanceOf(User::class, $this->em->find(User::class, $user->getId()));
    }
}
