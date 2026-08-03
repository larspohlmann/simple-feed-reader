<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\E2eSeedAdminSubscriptionCommand;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\UserStatus;
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
        $admin->setStatus(UserStatus::Active);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:e2e:seed-admin-subscription'));
    }

    private function countRows(string $table): int
    {
        $count = $this->em->getConnection()->executeQuery('SELECT COUNT(*) FROM ' . $table)->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function subscriptionCountFor(User $user): int
    {
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);

        return $subscriptions->countForUser((int) $user->getId());
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
     * The fixture feed points at a reserved host that never resolves, so it is
     * never fetched — but the row must still exist for the subscription to
     * reference it, carry a title (the magazine kicker "source"), and be marked
     * already-fetched so the reader skips its post-onboarding sweep.
     */
    public function testCreatesTheFixtureFeedItSubscribesTo(): void
    {
        $this->seedAdmin();

        $this->tester()->execute([]);

        $feed = $this->fixtureFeed();
        self::assertNotSame('', (string) $feed->getTitle());
        self::assertNotNull($feed->getLastFetchedAt());
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

    /**
     * An admin that already owns a real subscription still gets the fixture:
     * "at least one subscription" used to be the whole contract, but the reader
     * shell mounting is not enough — the #155 clip specs need THIS entry
     * visible, so the fixture is unconditional and additive.
     */
    public function testAddsTheFixtureAlongsideAnAdminsExistingSubscription(): void
    {
        $admin = $this->seedAdmin();

        $realFeed = new Feed('https://news.example.org/feed.xml');
        $this->em->persist($realFeed);
        $this->em->persist(new Subscription($admin, $realFeed, new \DateTimeImmutable('-1 hour')));
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame(2, $this->subscriptionCountFor($admin));
        self::assertInstanceOf(Feed::class, $this->fixtureFeed());
    }

    /**
     * A feed can outlive its entry — nothing else in this codebase deletes an
     * Entry without its Feed today, but the seed command must not assume that
     * stays true forever. A feed row surviving without its entry reproduces
     * the exact symptom of no fixture at all (an empty reader with the
     * subscription still in the sidebar), so a re-run must repair it rather
     * than trust the feed's mere existence.
     */
    public function testRecreatesTheEntryWhenTheFeedSurvivedWithoutIt(): void
    {
        $this->seedAdmin();
        $this->tester()->execute([]);

        $feed = $this->fixtureFeed();
        $this->em->remove($this->fixtureEntry($feed));
        $this->em->flush();
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
        $this->em->flush();

        $this->tester()->execute([]);

        $this->em->clear();
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
        $state->setIsRead(true);
        $this->em->persist($state);
        $this->em->flush();

        $this->tester()->execute([]);

        $this->em->clear();
        /** @var EntryStateRepository $entryStates */
        $entryStates = self::getContainer()->get(EntryStateRepository::class);
        $reloaded = $entryStates->findOneForUserEntry((int) $admin->getId(), (int) $entry->getId());
        self::assertInstanceOf(EntryState::class, $reloaded);
        self::assertFalse($reloaded->isRead());
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
            $this->em,
            $clock,
            'prod',
        );

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('disabled in the prod environment', $tester->getDisplay());
        self::assertSame(0, $this->subscriptionCountFor($admin));
    }
}
