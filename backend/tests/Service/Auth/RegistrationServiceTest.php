<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Entity\User;
use App\Enum\RegistrationMethod;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\PasswordWorkEqualizer;
use App\Service\Auth\ActionTokenService;
use App\Service\Auth\Exception\InvalidTokenException;
use App\Service\Auth\Factory\SignupUserFactory;
use App\Service\Auth\PasswordResetter;
use App\Service\Auth\RegistrationPolicy;
use App\Service\Auth\RegistrationService;
use App\Service\Auth\UserByEmail\UserByEmailInterface;
use App\Service\Mail\AccountMailer\AccountMailer;
use App\Service\Mail\AccountMailer\AccountMailerInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\AwaitingApprovalRecorder;
use App\Tests\Support\RegistrationPolicies;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class RegistrationServiceTest extends DbTestCase
{
    use RegistrationPolicies;

    /**
     * Builds the service against a repository that always reports "no such
     * user". That is exactly what two concurrent requests for the same fresh
     * address observe: both SELECT before either INSERT commits, so both pass
     * the duplicate check and both go on to insert.
     *
     * The HTTP layer cannot stage this - one PHP process handles one request at
     * a time - so the race is reproduced at the seam where it actually occurs.
     */
    private function serviceWithBlindDuplicateCheck(): RegistrationService
    {
        $container = self::getContainer();

        $blindRepository = $this->createStub(UserByEmailInterface::class);
        $blindRepository->method('findOneByEmail')->willReturn(null);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var ActionTokenService $tokens */
        $tokens = $container->get(ActionTokenService::class);
        /** @var AccountMailer $mailer */
        $mailer = $container->get(AccountMailer::class);
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        /** @var PasswordWorkEqualizer $work */
        $work = $container->get(PasswordWorkEqualizer::class);

        return new RegistrationService(
            $this->em,
            $blindRepository,
            $tokens,
            $mailer,
            $work,
            new EventDispatcher(),
            new SignupUserFactory($hasher, $clock, $this->registrationPolicy(confirm: true, approve: true)),
            new PasswordResetter($this->em, $hasher, $clock),
        );
    }

    /**
     * Builds a real service with real collaborators for everything except the
     * two side-effect seams a test needs to observe: the mailer and the event
     * dispatcher.
     */
    private function serviceUnderPolicy(
        RegistrationPolicy $policy,
        ?AccountMailerInterface $mailer = null,
        ?EventDispatcherInterface $events = null,
    ): RegistrationService {
        $container = self::getContainer();

        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var ActionTokenService $tokens */
        $tokens = $container->get(ActionTokenService::class);
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        /** @var PasswordWorkEqualizer $work */
        $work = $container->get(PasswordWorkEqualizer::class);

        return new RegistrationService(
            $this->em,
            $users,
            $tokens,
            $mailer ?? $this->createMock(AccountMailerInterface::class),
            $work,
            $events ?? new EventDispatcher(),
            new SignupUserFactory($hasher, $clock, $policy),
            new PasswordResetter($this->em, $hasher, $clock),
        );
    }

    public function testConfirmationOnLandsInPendingVerificationAndMails(): void
    {
        $policy = $this->registrationPolicy(confirm: true, approve: true);

        $capturedToken = null;
        $mailer = $this->createMock(AccountMailerInterface::class);
        $mailer->expects($this->once())
            ->method('sendVerification')
            ->with(self::isInstanceOf(User::class), self::isString())
            ->willReturnCallback(function (User $user, string $token) use (&$capturedToken): void {
                self::assertSame('newcomer@example.com', $user->getEmail());
                $capturedToken = $token;
            });
        $mailer->expects($this->never())->method('sendApproved');
        $mailer->expects($this->never())->method('sendPendingApprovalNotice');

        $recording = new AwaitingApprovalRecorder();

        $service = $this->serviceUnderPolicy($policy, $mailer, $recording->dispatcher);
        $service->register('newcomer@example.com', 'correct-horse-battery');

        $user = $this->users()->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
        self::assertNull($user->getApprovedAt());
        self::assertSame([], $recording->events());

        self::assertIsString($capturedToken);
        /** @var ActionTokenService $tokens */
        $tokens = self::getContainer()->get(ActionTokenService::class);
        self::assertSame($user, $tokens->consume($capturedToken, TokenPurpose::VerifyEmail));
    }

    public function testConfirmationOffApprovalOnLandsInPendingApprovalAndDispatches(): void
    {
        $policy = $this->registrationPolicy(confirm: false, approve: true);

        $mailer = $this->createMock(AccountMailerInterface::class);
        $mailer->expects($this->never())->method('sendVerification');
        $mailer->expects($this->never())->method('sendApproved');
        $mailer->expects($this->never())->method('sendPendingApprovalNotice');

        $recording = new AwaitingApprovalRecorder();

        $service = $this->serviceUnderPolicy($policy, $mailer, $recording->dispatcher);
        $service->register('awaiting@example.com', 'correct-horse-battery');

        $user = $this->users()->findOneByEmail('awaiting@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getApprovedAt());

        self::assertCount(1, $recording->events());
        self::assertSame($user, $recording->events()[0]->user);
        self::assertSame(RegistrationMethod::EmailPassword, $recording->events()[0]->method);
    }

    public function testBothGatesOffLandsActiveWithApprovedAtAndNoEventNoMail(): void
    {
        $policy = $this->registrationPolicy(confirm: false, approve: false);

        $mailer = $this->createMock(AccountMailerInterface::class);
        $mailer->expects($this->never())->method('sendVerification');
        $mailer->expects($this->never())->method('sendApproved');
        $mailer->expects($this->never())->method('sendPendingApprovalNotice');

        $recording = new AwaitingApprovalRecorder();

        $service = $this->serviceUnderPolicy($policy, $mailer, $recording->dispatcher);
        $service->register('instant@example.com', 'correct-horse-battery');

        $user = $this->users()->findOneByEmail('instant@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertNotNull($user->getApprovedAt());
        self::assertSame([], $recording->events());
    }

    public function testResettingWithAnUnknownTokenIsRefused(): void
    {
        $policy = $this->registrationPolicy(confirm: true, approve: false);
        $service = $this->serviceUnderPolicy($policy, $this->createStub(AccountMailerInterface::class));

        $this->expectException(InvalidTokenException::class);

        $service->resetPassword('never-issued', 'correct-horse-battery');
    }

    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }

    /**
     * The production scenario is a double-clicked submit button, which is
     * common enough to be a certainty rather than a risk. Losing the race must
     * look to the client exactly like winning it: anything else is both a 500
     * on a normal user action and a crack in the enumeration guarantee, since
     * a distinguishable response tells the caller a concurrent signup for that
     * address was in flight.
     */
    public function testLosingTheInsertRaceIsIndistinguishableFromWinningIt(): void
    {
        $service = $this->serviceWithBlindDuplicateCheck();

        $service->register('race@example.com', 'correct-horse-battery');

        // Must not throw. The unique index is the authority on who won; the
        // loser's job is to say nothing and let the winner's mail stand.
        $service->register('race@example.com', 'correct-horse-battery');

        // Counted over a separate connection: the losing flush closes the
        // EntityManager, so the ORM cannot be asked anything afterwards.
        self::assertSame(1, $this->countUsersWithEmail('race@example.com'));
    }

    private function countUsersWithEmail(string $email): int
    {
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM app_user WHERE email = ?',
            [$email],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
