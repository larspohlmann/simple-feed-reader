<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\UserStatus;
use App\Service\OAuth\OAuthAccountLinker;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Every case here is a rule about who gets handed which account, so a failure
 * in this file is an account takeover rather than a regression.
 */
final class OAuthAccountLinkerTest extends DbTestCase
{
    private const NOW = '2026-07-21 12:00:00';

    public function testAKnownIdentityResolvesToItsUser(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        $this->em->persist(new UserIdentity($user, 'google', 'sub-1', $this->now()));
        $this->em->flush();

        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        self::assertSame($user->getId(), $resolved->getId());
        self::assertSame(1, $this->countIdentities());
    }

    public function testAVerifiedAddressLinksToAnExistingActiveAccount(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);

        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'BOB@example.com', true));

        self::assertSame($user->getId(), $resolved->getId());
        self::assertSame(UserStatus::Active, $resolved->getStatus());
        self::assertSame(1, $this->countIdentities());
    }

    public function testAnUnverifiedAddressDoesNotLinkAndCreatesANewAccount(): void
    {
        // The takeover case. If a provider let someone claim an address they
        // do not own, linking on it would hand them the real owner's account.
        $existing = $this->persistUser('bob@example.com', UserStatus::Active);

        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'bob@example.com', false));

        self::assertNotSame($existing->getId(), $resolved->getId());
        self::assertSame(UserStatus::PendingApproval, $resolved->getStatus());
    }

    public function testAnUnverifiedAddressIsNotEvenTakenAsTheNewAccountsIdentifier(): void
    {
        // Refusing to LINK is only half the rule. If the unproven address
        // became the new account's login identifier, an attacker could park
        // `admin@company.example` in the approval queue, be approved on the
        // strength of how the address reads, and end up sharing one account
        // with the real owner once that owner recovered a password to it.
        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'admin@company.example', false));

        self::assertNotSame('admin@company.example', $resolved->getEmail());
        self::assertStringEndsWith('@oauth.invalid', $resolved->getEmail());
        // The claim is not thrown away, it is just filed where it cannot be
        // mistaken for something we verified.
        self::assertSame('admin@company.example', $this->onlyIdentity()->getEmail());
    }

    public function testAPrivateRelayAddressNeverLinks(): void
    {
        $existing = $this->persistUser('relay@privaterelay.appleid.com', UserStatus::Active);

        $resolved = $this->linker()->resolve(
            new OAuthIdentity('apple', 'sub-1', 'relay@privaterelay.appleid.com', true),
        );

        self::assertNotSame($existing->getId(), $resolved->getId());
    }

    public function testLinkingToAnUnverifiedAccountPromotesItAndWipesThePlantedPassword(): void
    {
        $planted = $this->persistUser('bob@example.com', UserStatus::PendingVerification);
        $planted->setPasswordHash('an-attackers-hash', new \DateTimeImmutable('2020-01-01 00:00:00'));
        $this->em->flush();

        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        self::assertSame($planted->getId(), $resolved->getId());
        self::assertSame(UserStatus::PendingApproval, $resolved->getStatus());
        // The credential was set by someone who never proved they own this
        // address. OAuth just proved somebody else does.
        self::assertNull($resolved->getPasswordHash());
        // And the wipe is stamped, which is what revokes any JWT the planter
        // is still holding: PasswordChangeTokenInvalidator rejects tokens
        // issued before this instant.
        self::assertEquals($this->now(), $resolved->getPasswordChangedAt());
    }

    public function testLinkingDoesNotReviveARejectedAccount(): void
    {
        $rejected = $this->persistUser('bob@example.com', UserStatus::Rejected);

        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        self::assertSame($rejected->getId(), $resolved->getId());
        self::assertSame(UserStatus::Rejected, $resolved->getStatus());
    }

    public function testLinkingDoesNotUnsuspendAnAccount(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Suspended);

        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        self::assertSame(UserStatus::Suspended, $resolved->getStatus());
    }

    public function testLinkingToAnActiveAccountDoesNotTouchItsPassword(): void
    {
        // The wipe is scoped to pending_verification and nothing else. An
        // active account's password was proven; a provider sign-in must not
        // silently disable it, and must not revoke that user's live sessions.
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        $user->setPasswordHash('a-real-hash', new \DateTimeImmutable('2020-01-01 00:00:00'));
        $this->em->flush();

        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        self::assertSame('a-real-hash', $resolved->getPasswordHash());
        self::assertEquals(new \DateTimeImmutable('2020-01-01 00:00:00'), $resolved->getPasswordChangedAt());
    }

    public function testANewAccountIsCreatedPendingApprovalWithNoPassword(): void
    {
        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'new@example.com', true));

        self::assertSame('new@example.com', $resolved->getEmail());
        // pending_approval, not pending_verification: the provider already
        // verified the address, so the double opt-in mail would be asking the
        // user to prove something we were just told by a party we trust more.
        self::assertSame(UserStatus::PendingApproval, $resolved->getStatus());
        self::assertNull($resolved->getPasswordHash());
        self::assertSame(1, $this->countIdentities());
    }

    /**
     * Apple can decline to send an address on repeat authorisations, so the
     * linker mints a placeholder — and every property of that placeholder is
     * load-bearing, which is why the address is pinned exactly rather than
     * merely checked for an `@`.
     *
     * `.invalid` is reserved by RFC 2606 and can never resolve, so the
     * account's notional address can never be delivered to a stranger. The
     * provider prefix tells the admin reviewing the queue what they are looking
     * at. The digest is of the SUBJECT, so the same identity reconstructs the
     * same address instead of accumulating an account per sign-in — and the
     * subject itself stays out of a column the admin UI displays.
     *
     * Recomputed here from the documented rule rather than copied from a run,
     * so a change to the derivation has to be made deliberately in both places.
     */
    public function testAnIdentityWithNoAddressGetsADeterministicNonRoutablePlaceholder(): void
    {
        $expected = 'apple-' . substr(hash('sha256', 'sub-1'), 0, 32) . '@oauth.invalid';

        $resolved = $this->linker()->resolve(new OAuthIdentity('apple', 'sub-1', null, false));

        self::assertSame($expected, $resolved->getEmail());
        self::assertSame(UserStatus::PendingApproval, $resolved->getStatus());
        self::assertNull($resolved->getPasswordHash());

        // Stable across sign-ins: the same identity must resolve to the same
        // account, not mint a second one.
        $again = $this->linker()->resolve(new OAuthIdentity('apple', 'sub-1', null, false));
        self::assertSame($resolved->getId(), $again->getId());
        self::assertSame(1, $this->countIdentities());
    }

    public function testTwoAddresslessIdentitiesDoNotCollide(): void
    {
        $first = $this->linker()->resolve(new OAuthIdentity('apple', 'sub-1', null, false));
        $second = $this->linker()->resolve(new OAuthIdentity('apple', 'sub-2', null, false));

        self::assertNotSame($first->getId(), $second->getId());
        self::assertNotSame($first->getEmail(), $second->getEmail());
    }

    public function testASecondProviderLinksToTheSameUser(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);

        $this->linker()->resolve(new OAuthIdentity('google', 'g-1', 'bob@example.com', true));
        $resolved = $this->linker()->resolve(new OAuthIdentity('apple', 'a-1', 'bob@example.com', true));

        self::assertSame($user->getId(), $resolved->getId());
        self::assertSame(2, $this->countIdentities());
    }

    public function testTheSameSubjectAtTwoProvidersResolvesToTwoDifferentAccounts(): void
    {
        // Subject identifiers are unique per provider, not globally. If the
        // lookup ever collapsed to `sub` alone, one provider's user would sign
        // in as another provider's.
        $first = $this->linker()->resolve(new OAuthIdentity('google', 'shared-sub', null, false));
        $second = $this->linker()->resolve(new OAuthIdentity('apple', 'shared-sub', null, false));

        self::assertNotSame($first->getId(), $second->getId());
    }

    public function testTheStoredIdentityEmailIsRefreshedWhenTheProviderChangesIt(): void
    {
        $user = $this->persistUser('bob@example.com', UserStatus::Active);
        $identity = new UserIdentity($user, 'google', 'sub-1', $this->now());
        $identity->setEmail('old@example.com');
        $this->em->persist($identity);
        $this->em->flush();

        $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'new@example.com', true));
        $this->em->clear();

        $reloaded = $this->em->getRepository(UserIdentity::class)
            ->findOneBy(['provider' => 'google', 'providerUserId' => 'sub-1']);

        self::assertNotNull($reloaded);
        self::assertSame('new@example.com', $reloaded->getEmail());
        // The IDENTITY's address changed. The USER's login address did not —
        // changing that from a provider callback would let a compromised
        // provider account rewrite the address our password reset mails go to.
        self::assertSame('bob@example.com', $reloaded->getUser()->getEmail());
    }

    /**
     * The takeover this ordering exists to stop. An attacker signs in once to
     * establish an identity, then changes the address on their provider account
     * to a victim's — verified, because they had to prove it to the provider to
     * change it... or because the provider is lax. Either way the linking rule
     * must never run for an identity we have already seen: rule 1 wins, and the
     * victim's account is not reachable from a provider profile edit.
     */
    public function testAChangedProviderAddressDoesNotMigrateAKnownIdentityOntoAnotherAccount(): void
    {
        $attacker = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'attacker@example.com', true));
        $victim = $this->persistUser('victim@example.com', UserStatus::Active);

        $resolved = $this->linker()->resolve(new OAuthIdentity('google', 'sub-1', 'victim@example.com', true));

        self::assertSame($attacker->getId(), $resolved->getId());
        self::assertNotSame($victim->getId(), $resolved->getId());
        // And the victim's row is untouched — no second identity was attached
        // to it, and its login address still belongs to the victim.
        self::assertSame(1, $this->countIdentities());
        self::assertSame('victim@example.com', $victim->getEmail());
        self::assertSame(UserStatus::Active, $victim->getStatus());
    }

    private function linker(): OAuthAccountLinker
    {
        /** @var \App\Repository\UserRepository $users */
        $users = $this->em->getRepository(User::class);
        /** @var \App\Repository\UserIdentityRepository $identities */
        $identities = $this->em->getRepository(UserIdentity::class);

        return new OAuthAccountLinker($this->em, $users, $identities, new MockClock(self::NOW));
    }

    private function persistUser(string $email, UserStatus $status): User
    {
        $user = new User($email, $this->now());
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function countIdentities(): int
    {
        return \count($this->em->getRepository(UserIdentity::class)->findAll());
    }

    private function onlyIdentity(): UserIdentity
    {
        $identities = $this->em->getRepository(UserIdentity::class)->findAll();

        self::assertCount(1, $identities);

        return $identities[0];
    }
}
