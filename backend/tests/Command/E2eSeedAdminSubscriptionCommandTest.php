<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\E2eSeedAdminSubscriptionCommand;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\UserStatus;
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

    public function testGivesTheAdminOneSubscription(): void
    {
        $admin = $this->seedAdmin();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame(1, $this->subscriptionCountFor($admin));
        self::assertStringContainsString('Subscribed', $tester->getDisplay());
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

        /** @var FeedRepository $feeds */
        $feeds = self::getContainer()->get(FeedRepository::class);
        $feed = $feeds->findOneBy(['url' => 'https://fixtures.sfr-e2e.example/feed.xml']);

        self::assertInstanceOf(Feed::class, $feed);
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
     * Idempotent: a second run finds the admin already subscribed and adds
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
     * "At least one" is the contract, not "exactly the fixture one". An admin
     * that already owns a real subscription is left untouched — no fixture feed
     * is created and no second row is added.
     */
    public function testLeavesAnAdminThatAlreadyHasASubscriptionAlone(): void
    {
        $admin = $this->seedAdmin();

        $realFeed = new Feed('https://news.example.org/feed.xml');
        $this->em->persist($realFeed);
        $this->em->persist(new Subscription($admin, $realFeed, new \DateTimeImmutable('-1 hour')));
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame(1, $this->subscriptionCountFor($admin));
        self::assertSame(1, $this->countRows('feed'), 'no fixture feed when a subscription already exists');
        self::assertStringContainsString('already owns', $tester->getDisplay());
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
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        $command = new E2eSeedAdminSubscriptionCommand($users, $subscriptions, $feeds, $this->em, $clock, 'prod');

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('disabled in the prod environment', $tester->getDisplay());
        self::assertSame(0, $this->subscriptionCountFor($admin));
    }
}
