<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Repository\UserIdentityRepository;
use App\Tests\DbTestCase;

final class UserIdentityRepositoryTest extends DbTestCase
{
    private UserIdentityRepository $identities;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserIdentityRepository $identities */
        $identities = $this->entityManager->getRepository(UserIdentity::class);
        $this->identities = $identities;
        $this->now = new \DateTimeImmutable('2026-07-21 12:00:00');
    }

    /** Built inline: UserFactory always hashes a password, and an OAuth-only account has none. */
    private function identity(string $email, string $provider, string $providerUserId): UserIdentity
    {
        $user = new User($email, $this->now);
        $this->entityManager->persist($user);

        $identity = new UserIdentity($user, $provider, $providerUserId, $this->now);
        $this->entityManager->persist($identity);

        return $identity;
    }

    public function testFindsAnIdentityByProviderAndSubject(): void
    {
        $identity = $this->identity('bob@example.com', 'google', 'sub-123');
        $this->entityManager->flush();

        $found = $this->identities->findOneByProviderAndSubject('google', 'sub-123');

        self::assertNotNull($found);
        self::assertSame($identity->getUser()->getId(), $found->getUser()->getId());
    }

    public function testTheSameSubjectAtADifferentProviderIsADifferentIdentity(): void
    {
        $this->identity('bob@example.com', 'google', 'sub-123');
        $this->entityManager->flush();

        // Subject identifiers are only unique within a provider. If this ever
        // returned the Google identity, an Apple account whose `sub` happened
        // to collide would sign in as somebody else.
        self::assertNull($this->identities->findOneByProviderAndSubject('apple', 'sub-123'));
    }

    /** Two rows share a subject, so this pins that the provider column is part of the predicate. */
    public function testACollidingSubjectResolvesToTheRightProvidersUser(): void
    {
        $google = $this->identity('bob@example.com', 'google', 'sub-123');
        $apple = $this->identity('alice@example.com', 'apple', 'sub-123');
        $this->entityManager->flush();

        $foundGoogle = $this->identities->findOneByProviderAndSubject('google', 'sub-123');
        $foundApple = $this->identities->findOneByProviderAndSubject('apple', 'sub-123');

        self::assertNotNull($foundGoogle);
        self::assertNotNull($foundApple);
        self::assertSame($google->getUser()->getId(), $foundGoogle->getUser()->getId());
        self::assertSame($apple->getUser()->getId(), $foundApple->getUser()->getId());
    }

    /**
     * Meaningful on MySQL only, whose default collation is case-insensitive: `provider_user_id` is pinned to
     * `utf8mb4_bin` (Version20260721181500) because `sub-abc` and `Sub-ABC` are two people.
     */
    public function testSubjectLookupIsCaseSensitive(): void
    {
        $this->identity('bob@example.com', 'google', 'Sub-ABC');
        $this->entityManager->flush();

        self::assertNull($this->identities->findOneByProviderAndSubject('google', 'sub-abc'));
        self::assertNotNull($this->identities->findOneByProviderAndSubject('google', 'Sub-ABC'));
    }

    public function testAnUnknownSubjectReturnsNull(): void
    {
        self::assertNull($this->identities->findOneByProviderAndSubject('google', 'nobody'));
    }

    /**
     * PasskeyRemovalPolicy's whole reason for calling this: a user with no
     * linked provider must not be told their passkey is safe to remove
     * because SOME OTHER account happens to have one.
     */
    public function testExistsForUserIsScopedToThatUser(): void
    {
        $linked = $this->identity('linked@example.com', 'google', 'sub-123');
        $unlinked = new User('unlinked@example.com', $this->now);
        $this->entityManager->persist($unlinked);
        $this->entityManager->flush();

        self::assertTrue($this->identities->existsForUser($linked->getUser()));
        self::assertFalse($this->identities->existsForUser($unlinked));
    }
}
