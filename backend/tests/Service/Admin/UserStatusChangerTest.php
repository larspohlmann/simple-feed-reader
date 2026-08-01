<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Enum\UserStatus;
use App\Exception\ValidationException;
use App\Service\Admin\SelfActionGuard;
use App\Service\Admin\UserStatusChanger;
use App\Service\Mail\AccountMailerInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The status-transition decisions extracted from AdminUserController — see
 * AdminUserControllerTest for the same behaviour proved end to end through
 * the HTTP layer. These cases pin the mail-or-silent decision and the
 * self-guard delegation directly against the service.
 */
final class UserStatusChangerTest extends DbTestCase
{
    private function factory(): UserFactory
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($this->em, $hasher);
    }

    /** @return MockObject&AccountMailerInterface */
    private function mailer(): MockObject
    {
        return $this->createMock(AccountMailerInterface::class);
    }

    /** reject() and suspend() never touch the mailer — a stub needs no expectations. */
    private function unusedMailer(): AccountMailerInterface
    {
        return $this->createStub(AccountMailerInterface::class);
    }

    private function service(AccountMailerInterface $mailer): UserStatusChanger
    {
        return new UserStatusChanger(
            $this->em,
            new MockClock('2026-07-15T00:00:00Z'),
            $mailer,
            new SelfActionGuard(),
        );
    }

    /**
     * @return iterable<string, array{UserStatus}>
     */
    public static function firstTimeGrantStatuses(): iterable
    {
        yield 'pending_approval' => [UserStatus::PendingApproval];
        yield 'pending_verification' => [UserStatus::PendingVerification];
        yield 'rejected' => [UserStatus::Rejected];
    }

    #[DataProvider('firstTimeGrantStatuses')]
    public function testApproveActivatesAndMailsOnAFirstTimeGrant(UserStatus $startingStatus): void
    {
        $user = $this->factory()->create('grant@example.com', status: $startingStatus);
        $mailer = $this->mailer();
        $mailer->expects(self::once())->method('sendApproved')->with($user);

        $this->service($mailer)->approve($user);

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-07-15T00:00:00Z'), $user->getApprovedAt());
    }

    /**
     * @return iterable<string, array{UserStatus}>
     */
    public static function silentStatuses(): iterable
    {
        yield 'suspended (restoration)' => [UserStatus::Suspended];
        yield 'active (no-op)' => [UserStatus::Active];
    }

    #[DataProvider('silentStatuses')]
    public function testApproveActivatesWithoutMailingWhenNothingIsGranted(UserStatus $startingStatus): void
    {
        $user = $this->factory()->create('silent@example.com', status: $startingStatus);
        $mailer = $this->mailer();
        $mailer->expects(self::never())->method('sendApproved');

        $this->service($mailer)->approve($user);

        self::assertSame(UserStatus::Active, $user->getStatus());
    }

    public function testRejectSetsTheStatus(): void
    {
        $admin = $this->factory()->create('boss@example.com');
        $target = $this->factory()->create('waiting@example.com', status: UserStatus::PendingApproval);

        $this->service($this->unusedMailer())->reject($target, $admin);

        self::assertSame(UserStatus::Rejected, $target->getStatus());
    }

    public function testRejectGuardsAgainstAnAdminActingOnThemselves(): void
    {
        $admin = $this->factory()->create('boss@example.com');

        $this->expectException(ValidationException::class);
        $this->service($this->unusedMailer())->reject($admin, $admin);
    }

    public function testSuspendSetsTheStatus(): void
    {
        $admin = $this->factory()->create('boss@example.com');
        $target = $this->factory()->create('member@example.com');

        $this->service($this->unusedMailer())->suspend($target, $admin);

        self::assertSame(UserStatus::Suspended, $target->getStatus());
    }

    public function testSuspendGuardsAgainstAnAdminActingOnThemselves(): void
    {
        $admin = $this->factory()->create('boss@example.com');

        $this->expectException(ValidationException::class);
        $this->service($this->unusedMailer())->suspend($admin, $admin);
    }
}
