<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PurgeUnverifiedUsersCommand;
use App\Entity\ActionToken;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\NewUserStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The ambient clock stays: the 48-hour boundary is far from any test's runtime, and the one case pinning the exact
 * cutoff builds the command with its own MockClock.
 */
final class PurgeUnverifiedUsersCommandTest extends DbTestCase
{
    private function seed(string $email, UserStatus $status, string $createdAt): User
    {
        $user = new User($email, new \DateTimeImmutable($createdAt));
        NewUserStatus::apply($user, $status, new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:users:purge-unverified'));
    }

    private function userCount(): int
    {
        /** @var int $count */
        $count = $this->entityManager
            ->createQuery('SELECT COUNT(u.id) FROM ' . User::class . ' u')
            ->getSingleScalarResult();

        return (int) $count;
    }

    /** Counted in SQL, not through the identity map, which can hold removed rows. */
    private function tokenCount(): int
    {
        /** @var int $count */
        $count = $this->entityManager->createQuery('SELECT COUNT(t.id) FROM ' . ActionToken::class . ' t')
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function testDeletesUnverifiedAccountsPastTheMaximumAge(): void
    {
        $this->seed('stale@example.com', UserStatus::PendingVerification, '-3 days');

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame(0, $this->userCount());
    }

    public function testKeepsUnverifiedAccountsInsideTheWindow(): void
    {
        $this->seed('fresh@example.com', UserStatus::PendingVerification, '-2 hours');

        $this->tester()->execute([]);

        self::assertSame(1, $this->userCount());
    }

    /**
     * Only unverified accounts are reclaimable. Anything past verification is a
     * real account — an ancient rejected or suspended row is a decision someone
     * made, not litter.
     *
     * @return iterable<string, array{UserStatus}>
     */
    public static function survivingStatuses(): iterable
    {
        yield 'active' => [UserStatus::Active];
        yield 'pending approval' => [UserStatus::PendingApproval];
        yield 'suspended' => [UserStatus::Suspended];
        yield 'rejected' => [UserStatus::Rejected];
    }

    #[DataProvider('survivingStatuses')]
    public function testNeverTouchesAccountsPastVerificationHoweverOld(UserStatus $status): void
    {
        $this->seed('survivor@example.com', $status, '-5 years');

        $this->tester()->execute([]);

        self::assertSame(1, $this->userCount());
    }

    public function testAssociatedTokensGoAwayWithTheUser(): void
    {
        $user = $this->seed('stale@example.com', UserStatus::PendingVerification, '-3 days');
        $this->entityManager->persist(new ActionToken(
            $user,
            TokenPurpose::VerifyEmail,
            str_repeat('a', 64),
            new \DateTimeImmutable('-2 days'),
            new \DateTimeImmutable('-3 days'),
        ));
        $this->entityManager->flush();
        self::assertSame(1, $this->tokenCount());

        $this->tester()->execute([]);

        self::assertSame(0, $this->userCount());
        self::assertSame(0, $this->tokenCount(), 'an orphaned token would outlive the account it belongs to');
    }

    public function testReportsHowManyAccountsItPurged(): void
    {
        $this->seed('stale1@example.com', UserStatus::PendingVerification, '-3 days');
        $this->seed('stale2@example.com', UserStatus::PendingVerification, '-9 days');
        $this->seed('keep@example.com', UserStatus::PendingVerification, '-1 hour');

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('Purged 2 unverified account(s).', $tester->getDisplay());
        self::assertSame(1, $this->userCount());
    }

    public function testReportsZeroWhenThereIsNothingToPurge(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('Purged 0 unverified account(s).', $tester->getDisplay());
    }

    /**
     * An OAuth account (an identity, no password) waits in pending_approval legitimately and could never recover from
     * deletion. Separate from survivingStatuses(), which still passes if a widened purge spares only password accounts.
     */
    public function testItNeverDeletesAnOAuthAccountAwaitingApproval(): void
    {
        $user = $this->seed('oauth@example.com', UserStatus::PendingApproval, '-30 days');
        $this->entityManager->persist(new UserIdentity($user, 'google', 'sub-1', new \DateTimeImmutable('-30 days')));
        $this->entityManager->flush();

        $this->tester()->execute([]);

        $this->entityManager->clear();
        self::assertNotNull(
            $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'oauth@example.com']),
            'an oauth account waiting for approval is not litter',
        );
    }

    /**
     * The exact boundary, pinned with a clock this test owns. A user created
     * one second before the cutoff goes; one second after it stays.
     */
    public function testTheCutoffIsExactlyFortyEightHours(): void
    {
        $now = new \DateTimeImmutable('2026-07-21 12:00:00');
        $this->seed('just-over@example.com', UserStatus::PendingVerification, '2026-07-19 11:59:59');
        $this->seed('just-under@example.com', UserStatus::PendingVerification, '2026-07-19 12:00:01');

        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        $command = new PurgeUnverifiedUsersCommand($users, $this->entityManager, new MockClock($now));

        (new CommandTester($command))->execute([]);

        $survivors = $this->entityManager
            ->createQuery('SELECT u.email FROM ' . User::class . ' u')
            ->getSingleColumnResult();
        self::assertSame(['just-under@example.com'], $survivors);
    }
}
