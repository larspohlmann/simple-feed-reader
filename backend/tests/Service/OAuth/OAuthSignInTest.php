<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Exception\AccountNotActiveException;
use App\Exception\InvalidTokenException;
use App\Service\OAuth\OAuthSignIn;
use App\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The two legs of turning a provider-verified identity into a session.
 *
 * Every rule here used to live in OAuthController, spread across two actions.
 * OAuthFlowTest still covers them over real HTTP; these tests pin them at the
 * seam, where the status gate and the one-answer-for-every-failure rule are
 * decided.
 */
final class OAuthSignInTest extends DbTestCase
{
    private const BROWSER = 'the-browser-token';

    public function testAVerifiedIdentityIsIssuedALoginCode(): void
    {
        $code = $this->signIn()->issueLoginCode($this->identity(), self::BROWSER);

        self::assertNotSame('', $code);
    }

    public function testAFirstSignInCreatesTheAccountItIssuesTheCodeFor(): void
    {
        self::assertNull($this->findUser('bob@example.com'));

        $code = $this->signIn()->issueLoginCode($this->identity(), self::BROWSER);
        $user = $this->findUser('bob@example.com');

        self::assertInstanceOf(User::class, $user);
        // The account is created but not yet usable, and the code is still
        // issued — the status gate belongs at the exchange, not here.
        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNotSame('', $code);
    }

    /**
     * @return iterable<string, array{UserStatus}>
     */
    public static function inactiveStatuses(): iterable
    {
        yield 'pending approval' => [UserStatus::PendingApproval];
        yield 'suspended' => [UserStatus::Suspended];
        yield 'rejected' => [UserStatus::Rejected];
    }

    #[DataProvider('inactiveStatuses')]
    public function testAnInactiveUserStillGetsACodeSoTheExchangeCanExplainWhy(
        UserStatus $status,
    ): void {
        // Refusing here would collapse "you are waiting for approval" into a
        // generic redirect error. The code is worth nothing on its own: it names
        // a user id, and the redemption re-runs the status gate before any token
        // is minted.
        $this->persistUser('bob@example.com', $status);

        $code = $this->signIn()->issueLoginCode($this->identity(), self::BROWSER);

        self::assertNotSame('', $code);
    }

    #[DataProvider('inactiveStatuses')]
    public function testAnInactiveUserCannotRedeemTheCode(UserStatus $status): void
    {
        $this->persistUser('bob@example.com', $status);
        $signIn = $this->signIn();
        $code = $signIn->issueLoginCode($this->identity(), self::BROWSER);

        // Not an InvalidTokenException: the user proved an identity and is owed
        // the reason, exactly as the password login gives it.
        $this->expectException(AccountNotActiveException::class);
        $signIn->redeemLoginCode($code, self::BROWSER);
    }

    public function testAnActiveUserRedeemsTheCodeForAToken(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $signIn = $this->signIn();
        $code = $signIn->issueLoginCode($this->identity(), self::BROWSER);

        $token = $signIn->redeemLoginCode($code, self::BROWSER);

        self::assertNotSame('', $token);
        // A JWT, not the login code echoed back.
        self::assertCount(3, explode('.', $token));
    }

    /**
     * Proves App\EventListener\StampLastLoginOnTokenIssue actually fires on
     * this path: redeemLoginCode() mints the token through the real
     * JwtManager/dispatcher, exactly as the HTTP endpoint does, so a direct
     * call to the listener could never stand in for this.
     */
    public function testRedeemingTheCodeStampsTheAccountsLastLoginAt(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        self::assertNull($user->getLastLoginAt());
        $signIn = $this->signIn();
        $code = $signIn->issueLoginCode($this->identity(), self::BROWSER);

        $signIn->redeemLoginCode($code, self::BROWSER);

        $this->em->clear();
        $reloaded = $this->findUser('bob@example.com');
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNotNull($reloaded->getLastLoginAt());
    }

    public function testALoginCodeCannotBeUsedTwice(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $signIn = $this->signIn();
        $code = $signIn->issueLoginCode($this->identity(), self::BROWSER);
        $signIn->redeemLoginCode($code, self::BROWSER);

        $this->expectException(InvalidTokenException::class);
        $signIn->redeemLoginCode($code, self::BROWSER);
    }

    public function testAnUnknownCodeIsRefused(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->signIn()->redeemLoginCode('never-issued', self::BROWSER);
    }

    public function testACodeCannotBeRedeemedByADifferentBrowser(): void
    {
        // The case a bearer code could never catch, and the reason the binding
        // exists at all.
        $this->persistUser('bob@example.com', UserStatus::Active);
        $signIn = $this->signIn();
        $code = $signIn->issueLoginCode($this->identity(), self::BROWSER);

        $this->expectException(InvalidTokenException::class);
        $signIn->redeemLoginCode($code, 'somebody-elses-browser');
    }

    public function testACodeCannotBeRedeemedWithNoBindingAtAll(): void
    {
        // A missing cookie is a failure, not a reason to skip the check.
        $this->persistUser('bob@example.com', UserStatus::Active);
        $signIn = $this->signIn();
        $code = $signIn->issueLoginCode($this->identity(), self::BROWSER);

        $this->expectException(InvalidTokenException::class);
        $signIn->redeemLoginCode($code, null);
    }

    public function testACodeForAnAccountDeletedBeforeTheExchangeIsRefused(): void
    {
        // Same answer as a bad code on purpose: there is nothing to sign in as,
        // and the two must not be distinguishable.
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        $signIn = $this->signIn();
        $code = $signIn->issueLoginCode($this->identity(), self::BROWSER);

        $this->em->remove($user);
        $this->em->flush();

        $this->expectException(InvalidTokenException::class);
        $signIn->redeemLoginCode($code, self::BROWSER);
    }

    private function signIn(): OAuthSignIn
    {
        /** @var OAuthSignIn $signIn */
        $signIn = self::getContainer()->get(OAuthSignIn::class);

        return $signIn;
    }

    private function identity(): OAuthIdentity
    {
        return new OAuthIdentity('google', 'sub-1', 'bob@example.com', true);
    }

    private function findUser(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function persistUser(string $email, UserStatus $status): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-21 12:00:00'));
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
