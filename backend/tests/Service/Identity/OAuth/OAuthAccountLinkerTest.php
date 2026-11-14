<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\RegistrationMethod;
use App\Enum\UserStatus;
use App\Service\Auth\RegistrationPolicy;
use App\Service\OAuth\Factory\OAuthUserFactory;
use App\Service\OAuth\Model\OAuthIdentityModel;
use App\Service\OAuth\OAuthAccountLinker;
use App\Tests\DbTestCase;
use App\Tests\Support\AwaitingApprovalRecorder;
use App\Tests\Support\NewUserStatus;
use App\Tests\Support\RegistrationPolicies;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Every case here is a rule about who gets handed which account, so a failure
 * in this file is an account takeover rather than a regression.
 */
final class OAuthAccountLinkerTest extends DbTestCase
{
    use RegistrationPolicies;

    private const NOW = '2026-07-21 12:00:00';

    public function testAKnownIdentityResolvesToItsUser(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        $this->entityManager->persist(new UserIdentity($user, 'google', 'sub-1', $this->now()));
        $this->entityManager->flush();

        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        self::assertSame($user->getId(), $resolved->getId());
        self::assertSame(1, $this->countIdentities());
    }

    public function testAVerifiedAddressLinksToAnExistingActiveAccount(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);

        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'BOB@example.com', true));

        self::assertSame($user->getId(), $resolved->getId());
        self::assertSame(UserStatus::Active, $resolved->getStatus());
        self::assertSame(1, $this->countIdentities());
    }

    public function testAnUnverifiedAddressDoesNotLinkAndCreatesANewAccount(): void
    {
        // The takeover case. If a provider let someone claim an address they
        // do not own, linking on it would hand them the real owner's account.
        $existing = $this->persistUser('bob@example.com', UserStatus::Active);

        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', false));

        self::assertNotSame($existing->getId(), $resolved->getId());
        self::assertSame(UserStatus::PendingApproval, $resolved->getStatus());
    }

    public function testAnUnverifiedAddressIsNotEvenTakenAsTheNewAccountsIdentifier(): void
    {
        // An unlinkable address must not become the login identifier either, or an attacker could squat
        // `admin@company.example` in the approval queue.
        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'admin@company.example', false));

        self::assertNotSame('admin@company.example', $resolved->getEmail());
        self::assertStringEndsWith('@oauth.invalid', $resolved->getEmail());
        // The claim is not thrown away, it is just filed where it cannot be
        // mistaken for something we verified.
        self::assertSame('admin@company.example', $this->onlyIdentity()->getEmail());
        // Unlinkable means unproven: nothing here earns the verification stamp.
        self::assertFalse($resolved->isEmailVerified());
    }

    public function testAPrivateRelayAddressNeverLinks(): void
    {
        $existing = $this->persistUser('relay@privaterelay.appleid.com', UserStatus::Active);

        $resolved = $this->linker()->resolve(
            new OAuthIdentityModel('apple', 'sub-1', 'relay@privaterelay.appleid.com', true),
        );

        self::assertNotSame($existing->getId(), $resolved->getId());
        // A private relay address is real and provider-verified, but it names
        // an (app, user) pair, not the person — never treated as proven.
        self::assertFalse($resolved->isEmailVerified());
    }

    public function testLinkingToAnUnverifiedAccountPromotesItAndWipesThePlantedPassword(): void
    {
        $planted = $this->persistUser('bob@example.com', UserStatus::PendingVerification);
        $planted->setPasswordHash('an-attackers-hash', new \DateTimeImmutable('2020-01-01 00:00:00'));
        $this->entityManager->flush();

        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        self::assertSame($planted->getId(), $resolved->getId());
        self::assertSame(UserStatus::PendingApproval, $resolved->getStatus());
        // The credential was set by someone who never proved they own this
        // address. OAuth just proved somebody else does.
        self::assertNull($resolved->getPasswordHash());
        // And the wipe is stamped, which is what revokes any JWT the planter
        // is still holding: InvalidatePasswordChangeTokensListener rejects tokens
        // issued before this instant.
        self::assertEquals($this->now(), $resolved->getPasswordChangedAt());
        // The provider proved this address, which is exactly what claimed the
        // row away from the planted, unverified registration.
        self::assertTrue($resolved->isEmailVerified());
    }

    public function testLinkingDoesNotReviveARejectedAccount(): void
    {
        $rejected = $this->persistUser('bob@example.com', UserStatus::Rejected);

        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        self::assertSame($rejected->getId(), $resolved->getId());
        self::assertSame(UserStatus::Rejected, $resolved->getStatus());
    }

    public function testLinkingDoesNotUnsuspendAnAccount(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Suspended);

        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        self::assertSame(UserStatus::Suspended, $resolved->getStatus());
    }

    public function testLinkingToAnActiveAccountDoesNotTouchItsPassword(): void
    {
        // The wipe is scoped to pending_verification and nothing else. An
        // active account's password was proven; a provider sign-in must not
        // silently disable it, and must not revoke that user's live sessions.
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        $user->setPasswordHash('a-real-hash', new \DateTimeImmutable('2020-01-01 00:00:00'));
        $this->entityManager->flush();

        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        self::assertSame('a-real-hash', $resolved->getPasswordHash());
        self::assertEquals(new \DateTimeImmutable('2020-01-01 00:00:00'), $resolved->getPasswordChangedAt());
    }

    public function testANewAccountIsCreatedPendingApprovalWithNoPassword(): void
    {
        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'new@example.com', true));

        self::assertSame('new@example.com', $resolved->getEmail());
        // pending_approval, not pending_verification: the provider already
        // verified the address, so the double opt-in mail would be asking the
        // user to prove something we were just told by a party we trust more.
        self::assertSame(UserStatus::PendingApproval, $resolved->getStatus());
        self::assertNull($resolved->getPasswordHash());
        self::assertSame(1, $this->countIdentities());
        // A linkable, provider-verified address proves the account.
        self::assertTrue($resolved->isEmailVerified());
    }

    /**
     * The placeholder is pinned exactly, recomputed from the rule rather than copied from a run: `.invalid` never
     * resolves, the prefix names the provider, and a digest of the subject keeps it stable and out of the admin UI.
     */
    public function testAnIdentityWithNoAddressGetsADeterministicNonRoutablePlaceholder(): void
    {
        $expected = 'apple-' . substr(hash('sha256', 'sub-1'), 0, 32) . '@oauth.invalid';

        $resolved = $this->linker()->resolve(new OAuthIdentityModel('apple', 'sub-1', null, false));

        self::assertSame($expected, $resolved->getEmail());
        self::assertSame(UserStatus::PendingApproval, $resolved->getStatus());
        self::assertNull($resolved->getPasswordHash());

        // Stable across sign-ins: the same identity must resolve to the same
        // account, not mint a second one.
        $again = $this->linker()->resolve(new OAuthIdentityModel('apple', 'sub-1', null, false));
        self::assertSame($resolved->getId(), $again->getId());
        self::assertSame(1, $this->countIdentities());
    }

    public function testTwoAddresslessIdentitiesDoNotCollide(): void
    {
        $first = $this->linker()->resolve(new OAuthIdentityModel('apple', 'sub-1', null, false));
        $second = $this->linker()->resolve(new OAuthIdentityModel('apple', 'sub-2', null, false));

        self::assertNotSame($first->getId(), $second->getId());
        self::assertNotSame($first->getEmail(), $second->getEmail());
    }

    public function testASecondProviderLinksToTheSameUser(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);

        $this->linker()->resolve(new OAuthIdentityModel('google', 'g-1', 'bob@example.com', true));
        $resolved = $this->linker()->resolve(new OAuthIdentityModel('apple', 'a-1', 'bob@example.com', true));

        self::assertSame($user->getId(), $resolved->getId());
        self::assertSame(2, $this->countIdentities());
    }

    public function testTheSameSubjectAtTwoProvidersResolvesToTwoDifferentAccounts(): void
    {
        // Subject identifiers are unique per provider, not globally. If the
        // lookup ever collapsed to `sub` alone, one provider's user would sign
        // in as another provider's.
        $first = $this->linker()->resolve(new OAuthIdentityModel('google', 'shared-sub', null, false));
        $second = $this->linker()->resolve(new OAuthIdentityModel('apple', 'shared-sub', null, false));

        self::assertNotSame($first->getId(), $second->getId());
    }

    public function testTheStoredIdentityEmailIsRefreshedWhenTheProviderChangesIt(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        $identity = new UserIdentity($user, 'google', 'sub-1', $this->now());
        $identity->setEmail('old@example.com');
        $this->entityManager->persist($identity);
        $this->entityManager->flush();

        $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'new@example.com', true));
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(UserIdentity::class)
            ->findOneBy(['provider' => 'google', 'providerUserId' => 'sub-1']);

        self::assertNotNull($reloaded);
        self::assertSame('new@example.com', $reloaded->getEmail());
        // The IDENTITY's address changed. The USER's login address did not —
        // changing that from a provider callback would let a compromised
        // provider account rewrite the address our password reset mails go to.
        self::assertSame('bob@example.com', $reloaded->getUser()->getEmail());
    }

    /**
     * A known identity whose provider address changes to a victim's must stay on its own account: rule 1 wins, so a
     * provider profile edit cannot reach another account.
     */
    public function testAChangedProviderAddressDoesNotMigrateAKnownIdentityOntoAnotherAccount(): void
    {
        $attacker = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'attacker@example.com', true));
        $victim = $this->persistUser('victim@example.com', UserStatus::Active);

        $resolved = $this->linker()->resolve(new OAuthIdentityModel('google', 'sub-1', 'victim@example.com', true));

        self::assertSame($attacker->getId(), $resolved->getId());
        self::assertNotSame($victim->getId(), $resolved->getId());
        // And the victim's row is untouched — no second identity was attached
        // to it, and its login address still belongs to the victim.
        self::assertSame(1, $this->countIdentities());
        self::assertSame('victim@example.com', $victim->getEmail());
        self::assertSame(UserStatus::Active, $victim->getStatus());
    }

    public function testANewOAuthAccountAnnouncesItselfToTheApprovalQueue(): void
    {
        $recording = new AwaitingApprovalRecorder();

        $this->linker($recording->dispatcher)
            ->resolve(new OAuthIdentityModel('google', 'sub-1', 'new@example.com', true));

        self::assertCount(1, $recording->events());
        self::assertSame(RegistrationMethod::OAuth, $recording->events()[0]->method);
        self::assertSame('google', $recording->events()[0]->oauthProvider);
        self::assertSame('new@example.com', $recording->events()[0]->user->getEmail());
    }

    public function testClaimingAnUnverifiedAccountAnnouncesItToTheApprovalQueue(): void
    {
        $this->persistUser('bob@example.com', UserStatus::PendingVerification);
        $recording = new AwaitingApprovalRecorder();

        $this->linker($recording->dispatcher)
            ->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        self::assertCount(1, $recording->events());
        self::assertSame(RegistrationMethod::OAuth, $recording->events()[0]->method);
    }

    public function testAReturningIdentityAnnouncesNothing(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        $this->entityManager->persist(new UserIdentity($user, 'google', 'sub-1', $this->now()));
        $this->entityManager->flush();
        $recording = new AwaitingApprovalRecorder();

        $this->linker($recording->dispatcher)
            ->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        self::assertSame([], $recording->events());
    }

    public function testLinkingToAnAlreadyActiveAccountAnnouncesNothing(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $recording = new AwaitingApprovalRecorder();

        $this->linker($recording->dispatcher)
            ->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        self::assertSame([], $recording->events());
    }

    public function testNewOAuthUserWithApprovalOffIsActiveWithNoEvent(): void
    {
        $recording = new AwaitingApprovalRecorder();

        $resolved = $this->linker($recording->dispatcher, $this->registrationPolicy(confirm: true, approve: false))
            ->resolve(new OAuthIdentityModel('google', 'sub-1', 'new@example.com', true));

        self::assertSame(UserStatus::Active, $resolved->getStatus());
        self::assertEquals($this->now(), $resolved->getApprovedAt());
        self::assertSame([], $recording->events());
    }

    public function testClaimUnverifiedWithApprovalOffActivatesAndWipesPasswordNoEvent(): void
    {
        $planted = $this->persistUser('bob@example.com', UserStatus::PendingVerification);
        $planted->setPasswordHash('an-attackers-hash', new \DateTimeImmutable('2020-01-01 00:00:00'));
        $this->entityManager->flush();
        $recording = new AwaitingApprovalRecorder();

        $resolved = $this->linker($recording->dispatcher, $this->registrationPolicy(confirm: true, approve: false))
            ->resolve(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        self::assertSame($planted->getId(), $resolved->getId());
        self::assertSame(UserStatus::Active, $resolved->getStatus());
        self::assertEquals($this->now(), $resolved->getApprovedAt());
        // Still a security control regardless of the approval toggle: the
        // credential belongs to whoever planted the unverified registration,
        // not to the party OAuth just proved owns the address.
        self::assertNull($resolved->getPasswordHash());
        self::assertEquals($this->now(), $resolved->getPasswordChangedAt());
        self::assertSame([], $recording->events());
    }

    private function linker(
        ?EventDispatcherInterface $events = null,
        ?RegistrationPolicy $policy = null,
    ): OAuthAccountLinker {
        /** @var \App\Repository\UserRepository $users */
        $users = $this->entityManager->getRepository(User::class);
        /** @var \App\Repository\UserIdentityRepository $identities */
        $identities = $this->entityManager->getRepository(UserIdentity::class);
        $policy ??= $this->registrationPolicy(confirm: true, approve: true);
        $clock = new MockClock(self::NOW);

        return new OAuthAccountLinker(
            $this->entityManager,
            $users,
            $identities,
            $clock,
            $events ?? new EventDispatcher(),
            $policy,
            new OAuthUserFactory($clock, $policy),
        );
    }

    private function persistUser(string $email, UserStatus $status): User
    {
        $user = new User($email, $this->now());
        NewUserStatus::apply($user, $status, $this->now());
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function countIdentities(): int
    {
        return \count($this->entityManager->getRepository(UserIdentity::class)->findAll());
    }

    private function onlyIdentity(): UserIdentity
    {
        $identities = $this->entityManager->getRepository(UserIdentity::class)->findAll();

        self::assertCount(1, $identities);

        return $identities[0];
    }
}
