<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Entity\User;
use App\Enum\RegistrationMethod;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Event\UserAwaitingApproval;
use App\Repository\UserRepository;
use App\Service\Auth\ActionTokenService;
use App\Service\Auth\EmailVerifier;
use App\Service\Auth\Exception\InvalidTokenException;
use App\Service\Auth\RegistrationPolicy;
use App\Tests\DbTestCase;
use App\Tests\Support\RegistrationPolicies;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class EmailVerifierTest extends DbTestCase
{
    use RegistrationPolicies;

    public function testWithApprovalOnTheVerifiedAccountQueuesForApprovalAndDispatches(): void
    {
        $policy = $this->registrationPolicy(confirm: true, approve: true);
        $token = $this->pendingAccountToken('verifier-approval-on@example.com');
        $captured = [];

        $status = $this->verifier($policy, $this->recordingDispatcher($captured))->verify($token);

        self::assertSame(UserStatus::PendingApproval, $status);
        $user = $this->users()->findOneByEmail('verifier-approval-on@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getApprovedAt());
        self::assertTrue($user->isEmailVerified());
        self::assertCount(1, $captured);
        self::assertSame($user, $captured[0]->user);
        self::assertSame(RegistrationMethod::EmailPassword, $captured[0]->method);
    }

    public function testWithApprovalOffTheVerifiedAccountIsActiveWithoutAnEvent(): void
    {
        $policy = $this->registrationPolicy(confirm: true, approve: false);
        $token = $this->pendingAccountToken('verifier-approval-off@example.com');
        $captured = [];

        $status = $this->verifier($policy, $this->recordingDispatcher($captured))->verify($token);

        self::assertSame(UserStatus::Active, $status);
        $user = $this->users()->findOneByEmail('verifier-approval-off@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertNotNull($user->getApprovedAt());
        self::assertTrue($user->isEmailVerified());
        self::assertSame([], $captured);

        // Past the identity map: the approval was flushed, not only set in memory.
        $this->em->clear();
        $reloaded = $this->users()->findOneByEmail('verifier-approval-off@example.com');
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserStatus::Active, $reloaded->getStatus());
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $verifier = $this->verifier($this->registrationPolicy(confirm: true, approve: false), new EventDispatcher());

        $this->expectException(InvalidTokenException::class);

        $verifier->verify('never-issued');
    }

    private function pendingAccountToken(string $email): string
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01 10:00:00'));
        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
        $this->em->persist($user);
        $this->em->flush();

        return $this->tokens()->issue($user, TokenPurpose::VerifyEmail);
    }

    private function verifier(RegistrationPolicy $policy, EventDispatcherInterface $events): EmailVerifier
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return new EmailVerifier($this->tokens(), $policy, $this->em, $events, $clock);
    }

    /**
     * @param list<UserAwaitingApproval> $captured
     *
     * @param-out list<UserAwaitingApproval> $captured
     */
    private function recordingDispatcher(array &$captured): EventDispatcherInterface
    {
        $events = new EventDispatcher();
        $events->addListener(
            UserAwaitingApproval::class,
            static function (UserAwaitingApproval $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        return $events;
    }

    private function tokens(): ActionTokenService
    {
        /** @var ActionTokenService $tokens */
        $tokens = self::getContainer()->get(ActionTokenService::class);

        return $tokens;
    }

    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }
}
