<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PurgeUnverifiedUsersCommand;
use App\Entity\ActionToken;
use App\Entity\User;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Clock strategy: the ambient clock is left alone. Cases that only care about
 * "old" versus "recent" seed createdAt relative to real now — the boundary is
 * 48 hours away, far outside any plausible test runtime. The one case that
 * pins the exact cutoff constructs the command with its own MockClock, so no
 * other test in the suite is affected.
 */
final class PurgeUnverifiedUsersCommandTest extends DbTestCase
{
    private function seed(string $email, UserStatus $status, string $createdAt): User
    {
        $user = new User($email, new \DateTimeImmutable($createdAt));
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();

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
        $count = $this->em->createQuery('SELECT COUNT(u.id) FROM ' . User::class . ' u')->getSingleScalarResult();

        return (int) $count;
    }

    /** Counted in SQL, not through the identity map, which can hold removed rows. */
    private function tokenCount(): int
    {
        /** @var int $count */
        $count = $this->em->createQuery('SELECT COUNT(t.id) FROM ' . ActionToken::class . ' t')
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
        $this->em->persist(new ActionToken(
            $user,
            TokenPurpose::VerifyEmail,
            str_repeat('a', 64),
            new \DateTimeImmutable('-2 days'),
            new \DateTimeImmutable('-3 days'),
        ));
        $this->em->flush();
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
        $command = new PurgeUnverifiedUsersCommand($users, $this->em, new MockClock($now));

        (new CommandTester($command))->execute([]);

        $survivors = $this->em->createQuery('SELECT u.email FROM ' . User::class . ' u')->getSingleColumnResult();
        self::assertSame(['just-under@example.com'], $survivors);
    }
}
