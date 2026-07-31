<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Security\AccountStatusException;
use App\Security\TrialExpiryGuard;
use App\Security\UserChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UserCheckerTest extends TestCase
{
    private function user(UserStatus $status): User
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-21 09:00:00'));
        $user->setStatus($status);

        return $user;
    }

    private function guard(): TrialExpiryGuard
    {
        return new TrialExpiryGuard(
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-07-21T09:00:00Z'),
        );
    }

    public function testActiveUserPasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new UserChecker($this->guard()))->checkPreAuth($this->user(UserStatus::Active));
    }

    /** @return iterable<string, array{UserStatus}> */
    public static function blockedStatuses(): iterable
    {
        yield 'pending verification' => [UserStatus::PendingVerification];
        yield 'pending approval' => [UserStatus::PendingApproval];
        yield 'suspended' => [UserStatus::Suspended];
        yield 'rejected' => [UserStatus::Rejected];
    }

    #[DataProvider('blockedStatuses')]
    public function testNonActiveUsersAreRejected(UserStatus $status): void
    {
        $this->expectException(AccountStatusException::class);

        (new UserChecker($this->guard()))->checkPreAuth($this->user($status));
    }

    public function testTheRejectionNamesTheStatus(): void
    {
        try {
            (new UserChecker($this->guard()))->checkPreAuth($this->user(UserStatus::Suspended));
            self::fail('Expected AccountStatusException');
        } catch (AccountStatusException $exception) {
            self::assertSame('suspended', $exception->accountStatus);
        }
    }

    public function testIgnoresForeignUserImplementations(): void
    {
        $this->expectNotToPerformAssertions();

        (new UserChecker($this->guard()))->checkPreAuth(new InMemoryUser('someone', null));
    }

    public function testCheckPostAuthLeavesEveryoneAlone(): void
    {
        $this->expectNotToPerformAssertions();

        (new UserChecker($this->guard()))->checkPostAuth($this->user(UserStatus::Suspended));
    }

    public function testExpiredTrialIsRejectedEvenWhenStoredStatusIsStillActive(): void
    {
        $user = $this->user(UserStatus::Active);
        $user->setTrialEndsAt(new \DateTimeImmutable('2026-07-20T09:00:00Z'));

        $this->expectException(AccountStatusException::class);
        (new UserChecker($this->guard()))->checkPreAuth($user);
    }

    /**
     * Symfony serializes authentication exceptions in some flows; the parent
     * only serializes its own $user, so the status must survive a round trip.
     */
    public function testStatusSurvivesSerialization(): void
    {
        $exception = new AccountStatusException('suspended');
        $exception->setUser($this->user(UserStatus::Suspended));

        $restored = unserialize(serialize($exception));

        self::assertInstanceOf(AccountStatusException::class, $restored);
        self::assertSame('suspended', $restored->accountStatus);
        self::assertSame('Account is not active.', $restored->getMessageKey());
        self::assertInstanceOf(User::class, $restored->getUser());
    }
}
