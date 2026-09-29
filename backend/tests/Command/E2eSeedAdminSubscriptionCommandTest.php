<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\E2eSeedAdminSubscriptionCommand;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class E2eSeedAdminSubscriptionCommandTest extends DbTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@example.com';
    private const string FIXTURE_FEED_URL = 'https://fixtures.sfr-e2e.example/feed.xml';

    private function seedAdmin(): User
    {
        $admin = new User(self::ADMIN_EMAIL, new \DateTimeImmutable('-1 day'));
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->approve(new \DateTimeImmutable('-1 day'));
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:e2e:seed-admin-subscription'));
    }

    private function countRows(string $table): int
    {
        $count = $this->entityManager->getConnection()->executeQuery('SELECT COUNT(*) FROM ' . $table)->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function subscriptionCountFor(User $user): int
    {
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);

        return $subscriptions->countForUser($user->requireId());
    }

    private function fixtureFeed(): Feed
    {
        /** @var FeedRepository $feeds */
        $feeds = self::getContainer()->get(FeedRepository::class);
        $feed = $feeds->findOneBy(['url' => self::FIXTURE_FEED_URL]);

        self::assertInstanceOf(Feed::class, $feed);

        return $feed;
    }

    private function fixtureEntry(Feed $feed): Entry
    {
        /** @var EntryRepository $entries */
        $entries = self::getContainer()->get(EntryRepository::class);
        $entry = $entries->findOneBy(['feed' => $feed]);

        self::assertInstanceOf(Entry::class, $entry);

        return $entry;
    }

    public function testGivesTheAdminASubscription(): void
    {
        $admin = $this->seedAdmin();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame(1, $this->subscriptionCountFor($admin));
        self::assertStringContainsString('ready and visible', $tester->getDisplay());
    }

    /**
     * The fixture feed is never fetched, but the subscription needs its row: titled (the magazine kicker's source) and
     * marked fetched, so the reader skips its post-onboarding sweep.
     */
    public function testCreatesTheFixtureFeedItSubscribesTo(): void
    {
        $this->seedAdmin();

        $this->tester()->execute([]);

        $feed = $this->fixtureFeed();
        self::assertNotSame('', (string) $feed->getTitle());
        self::assertNotNull($feed->getLastFetchedAt());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertEquals(
            $feed->getLastFetchedAt()->modify('+60 minutes'),
            $feed->getNextFetchAt(),
        );
    }

    /**
     * The magazine-kicker smokes measure a rendered row, so the fixture feed
     * must carry at least one entry to render — an empty feed leaves the reader
     * mounted but the magazine view blank.
     */
    public function testSeedsAnEntryToRender(): void
    {
        $this->seedAdmin();

        $this->tester()->execute([]);

        self::assertSame(1, $this->countRows('entry'));
    }

    /** A real entry always carries publishedAt; without it the fixture would change what the kicker smokes render. */
    public function testSeedsTheEntryWithAPublishedDate(): void
    {
        $this->seedAdmin();

        $this->tester()->execute([]);

        $entry = $this->fixtureEntry($this->fixtureFeed());
        self::assertSame($entry->getCreatedAt(), $entry->getPublishedAt());
    }

    /**
     * Idempotent: a second run finds everything already in place and adds
     * nothing, so repeated e2e runs never pile up fixture rows.
     */
    public function testIsIdempotentAcrossRepeatedRuns(): void
    {
        $admin = $this->seedAdmin();

        $this->tester()->execute([]);
        $this->tester()->execute([]);
        $this->tester()->execute([]);

        self::assertSame(1, $this->subscriptionCountFor($admin));
        self::assertSame(1, $this->countRows('subscription'));
        self::assertSame(1, $this->countRows('feed'));
        self::assertSame(1, $this->countRows('entry'));
    }

    /** The fixture is additive: the one-line-clip specs need THIS entry visible, not just any subscription. */
    public function testAddsTheFixtureAlongsideAnAdminsExistingSubscription(): void
    {
        $admin = $this->seedAdmin();

        $realFeed = new Feed('https://news.example.org/feed.xml');
        $this->entityManager->persist($realFeed);
        $this->entityManager->persist(new Subscription($admin, $realFeed, new \DateTimeImmutable('-1 hour')));
        $this->entityManager->flush();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame(2, $this->subscriptionCountFor($admin));
        self::assertInstanceOf(Feed::class, $this->fixtureFeed());
    }

    /** A feed that survived without its entry looks exactly like no fixture at all, so a re-run recreates the entry. */
    public function testRecreatesTheEntryWhenTheFeedSurvivedWithoutIt(): void
    {
        $this->seedAdmin();
        $this->tester()->execute([]);

        $feed = $this->fixtureFeed();
        $this->entityManager->remove($this->fixtureEntry($feed));
        $this->entityManager->flush();
        self::assertSame(0, $this->countRows('entry'));

        $this->tester()->execute([]);

        self::assertSame(1, $this->countRows('entry'));
        self::assertSame(1, $this->countRows('feed'), 'the existing feed row is reused, not duplicated');
    }

    /**
     * A subscription's markedReadUntil watermark hides every entry at or
     * before it in the reader's default unread view — the same failure mode
     * as a missing entry, from the UI's point of view. A re-run must clear it.
     */
    public function testClearsAMarkedReadUntilWatermarkThatWouldHideTheEntry(): void
    {
        $admin = $this->seedAdmin();
        $this->tester()->execute([]);

        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        $feed = $this->fixtureFeed();
        $subscription = $subscriptions->findOneBy(['user' => $admin, 'feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('+1 hour'));
        $this->entityManager->flush();

        $this->tester()->execute([]);

        $this->entityManager->clear();
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        $reloaded = $subscriptions->findOneBy(['user' => $admin, 'feed' => $this->fixtureFeed()]);
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertNull($reloaded->getMarkedReadUntil());
    }

    /**
     * An entry_state row marking the fixture entry read hides it from the
     * default unread view exactly as a missing entry would. A re-run must
     * flip it back rather than leave a stale read marker behind.
     */
    public function testUnreadsTheEntryWhenAnEntryStateMarkedItRead(): void
    {
        $admin = $this->seedAdmin();
        $this->tester()->execute([]);

        $entry = $this->fixtureEntry($this->fixtureFeed());
        $state = new EntryState($admin, $entry);
        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $this->tester()->execute([]);

        $this->entityManager->clear();
        /** @var EntryStateRepository $entryStates */
        $entryStates = self::getContainer()->get(EntryStateRepository::class);
        $reloaded = $entryStates->findOneForUserEntry($admin->requireId(), $entry->requireId());
        self::assertInstanceOf(EntryState::class, $reloaded);
        self::assertFalse($reloaded->isHidden());
        self::assertNull($reloaded->getHiddenAt());
    }

    public function testFailsWhenTheAdminDoesNotExistYet(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No admin e2e-admin@example.com to subscribe.', $tester->getDisplay());
        self::assertSame(0, $this->countRows('subscription'));
    }

    public function testRefusesToRunInProd(): void
    {
        $admin = $this->seedAdmin();

        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        /** @var FeedRepository $feeds */
        $feeds = self::getContainer()->get(FeedRepository::class);
        /** @var EntryRepository $entries */
        $entries = self::getContainer()->get(EntryRepository::class);
        /** @var EntryStateRepository $entryStates */
        $entryStates = self::getContainer()->get(EntryStateRepository::class);
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        $command = new E2eSeedAdminSubscriptionCommand(
            $users,
            $subscriptions,
            $feeds,
            $entries,
            $entryStates,
            $this->entityManager,
            $clock,
            'prod',
        );

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('disabled in the prod environment', $tester->getDisplay());
        self::assertSame(0, $this->subscriptionCountFor($admin));
    }
}
