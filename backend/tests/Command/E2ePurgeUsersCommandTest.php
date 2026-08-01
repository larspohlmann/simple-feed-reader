<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\E2ePurgeUsersCommand;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The seeded admin's email, which app:e2e:seed-admin creates and the Playwright
 * specs log in with. It matches the `e2e-` fixture prefix, so the purge has to
 * exclude it by name — the reason for the survives-the-purge test below.
 */
final class E2ePurgeUsersCommandTest extends DbTestCase
{
    private const string SEEDED_ADMIN_EMAIL = 'e2e-admin@example.com';

    private function seedUser(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('-1 day'));
        $user->setStatus(UserStatus::Active);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:e2e:purge-users'));
    }

    /**
     * Counted straight from the table, not through the identity map (which can
     * hold removed rows) and not through DQL (entry_state has a composite key
     * and no scalar id to count).
     */
    private function countRows(string $table): int
    {
        $count = $this->em->getConnection()->executeQuery('SELECT COUNT(*) FROM ' . $table)->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /** @return list<string> */
    private function survivingEmails(): array
    {
        /** @var list<string> $emails */
        $emails = $this->em->createQuery('SELECT u.email FROM ' . User::class . ' u ORDER BY u.email ASC')
            ->getSingleColumnResult();

        return $emails;
    }

    public function testDeletesBothFixturePatterns(): void
    {
        $this->seedUser('e2e-1234abcd@example.com');
        $this->seedUser('onboarding-1700000000-42@example.com');

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame([], $this->survivingEmails());
    }

    public function testKeepsTheSeededAdmin(): void
    {
        $this->seedUser(self::SEEDED_ADMIN_EMAIL);
        $this->seedUser('e2e-9999ffff@example.com');

        $this->tester()->execute([]);

        self::assertSame([self::SEEDED_ADMIN_EMAIL], $this->survivingEmails());
    }

    /**
     * Only the two fixture shapes are litter. A real account — one at a real
     * domain, or one at example.com without a fixture prefix — is never touched,
     * because the patterns pin both the prefix and the `@example.com` suffix.
     */
    public function testNeverTouchesRealAccounts(): void
    {
        $this->seedUser('alice@example.org');
        $this->seedUser('member@example.com');
        $this->seedUser('e2e-cafe@example.com');

        $this->tester()->execute([]);

        self::assertSame(['alice@example.org', 'member@example.com'], $this->survivingEmails());
    }

    public function testReportsHowManyAccountsItPurged(): void
    {
        $this->seedUser('e2e-aaaa@example.com');
        $this->seedUser('onboarding-1-2@example.com');
        $this->seedUser(self::SEEDED_ADMIN_EMAIL);

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('Purged 2 e2e fixture account(s).', $tester->getDisplay());
        self::assertSame([self::SEEDED_ADMIN_EMAIL], $this->survivingEmails());
    }

    public function testReportsZeroWhenThereIsNothingToPurge(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('Purged 0 e2e fixture account(s).', $tester->getDisplay());
    }

    /**
     * A fixture account owns subscriptions, tags and read state. All of them
     * must leave with it — an orphan row would outlive the account it belongs to
     * and keep polluting the per-user views the issue is about. The child rows
     * follow via their FK ON DELETE CASCADE, which this asserts end to end.
     */
    public function testChildRowsLeaveWithTheAccount(): void
    {
        $fixture = $this->seedUser('e2e-owner@example.com');

        $feed = new Feed('https://example.com/feed.xml');
        $entry = new Entry($feed, 'guid-1', 'https://example.com/1', 'First', new \DateTimeImmutable('-1 day'));
        $this->em->persist($feed);
        $this->em->persist($entry);
        $this->em->persist(new Subscription($fixture, $feed, new \DateTimeImmutable('-1 day')));
        $this->em->persist(new Tag($fixture, 'News'));
        $this->em->persist(new EntryState($fixture, $entry));
        $this->em->flush();

        self::assertSame(1, $this->countRows('subscription'));
        self::assertSame(1, $this->countRows('tag'));
        self::assertSame(1, $this->countRows('entry_state'));

        $this->tester()->execute([]);

        self::assertSame([], $this->survivingEmails());
        self::assertSame(0, $this->countRows('subscription'), 'an orphan subscription would outlive its owner');
        self::assertSame(0, $this->countRows('tag'), 'an orphan tag would outlive its owner');
        self::assertSame(0, $this->countRows('entry_state'), 'an orphan read-state row would outlive its owner');

        // The shared feed and entry are not per-user, so they stay.
        self::assertSame(1, $this->countRows('feed'));
        self::assertSame(1, $this->countRows('entry'));
    }

    /**
     * The safety rail: pointed at anything but dev/test, the command refuses and
     * exits non-zero, so it can never be aimed at production data by accident.
     */
    public function testRefusesToRunOutsideDevOrTest(): void
    {
        $fixture = $this->seedUser('e2e-should-survive@example.com');

        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        $command = new E2ePurgeUsersCommand($users, $this->em, 'prod');

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('only runs in the dev or test environment', $tester->getDisplay());

        self::assertSame([$fixture->getEmail()], $this->survivingEmails());
    }
}
