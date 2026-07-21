<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Repository\UserIdentityRepository;
use App\Tests\DbTestCase;

final class UserIdentityRepositoryTest extends DbTestCase
{
    private UserIdentityRepository $repository;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserIdentityRepository $repository */
        $repository = $this->em->getRepository(UserIdentity::class);
        $this->repository = $repository;
        $this->now = new \DateTimeImmutable('2026-07-21 12:00:00');
    }

    /**
     * Built inline rather than through Support\UserFactory: that factory always
     * hashes a password, and an OAuth-only account is precisely one that has
     * none. Paying for a bcrypt hash per fixture would also be a needless cost
     * in a test that never authenticates.
     */
    private function identity(string $email, string $provider, string $providerUserId): UserIdentity
    {
        $user = new User($email, $this->now);
        $this->em->persist($user);

        $identity = new UserIdentity($user, $provider, $providerUserId, $this->now);
        $this->em->persist($identity);

        return $identity;
    }

    public function testFindsAnIdentityByProviderAndSubject(): void
    {
        $identity = $this->identity('bob@example.com', 'google', 'sub-123');
        $this->em->flush();

        $found = $this->repository->findOneByProviderAndSubject('google', 'sub-123');

        self::assertNotNull($found);
        self::assertSame($identity->getUser()->getId(), $found->getUser()->getId());
    }

    public function testTheSameSubjectAtADifferentProviderIsADifferentIdentity(): void
    {
        $this->identity('bob@example.com', 'google', 'sub-123');
        $this->em->flush();

        // Subject identifiers are only unique within a provider. If this ever
        // returned the Google identity, an Apple account whose `sub` happened
        // to collide would sign in as somebody else.
        self::assertNull($this->repository->findOneByProviderAndSubject('apple', 'sub-123'));
    }

    /**
     * The converse of the test above, and the one that actually proves the
     * provider column is part of the predicate: with two rows sharing a
     * subject, a query that ignored the provider could still return "a" row and
     * pass the null-check above by accident. This one pins which row.
     */
    public function testACollidingSubjectResolvesToTheRightProvidersUser(): void
    {
        $google = $this->identity('bob@example.com', 'google', 'sub-123');
        $apple = $this->identity('alice@example.com', 'apple', 'sub-123');
        $this->em->flush();

        $foundGoogle = $this->repository->findOneByProviderAndSubject('google', 'sub-123');
        $foundApple = $this->repository->findOneByProviderAndSubject('apple', 'sub-123');

        self::assertNotNull($foundGoogle);
        self::assertNotNull($foundApple);
        self::assertSame($google->getUser()->getId(), $foundGoogle->getUser()->getId());
        self::assertSame($apple->getUser()->getId(), $foundApple->getUser()->getId());
    }

    /**
     * Only the exact-match half is asserted. Whether `sub-abc` also finds a row
     * stored as `Sub-ABC` is decided by the column's collation, not by this
     * repository: SQLite matches case-sensitively, MySQL's utf8mb4 default
     * (`utf8mb4_0900_ai_ci`) does not. Both legs of the CI matrix therefore
     * cannot agree, so asserting either way would green one and red the other.
     *
     * That divergence is a live hazard rather than a curiosity — see the note
     * on UserIdentityRepository::findOneByProviderAndSubject(). It is recorded
     * there instead of pinned here precisely because it is not yet fixed, and a
     * test asserting today's behaviour would cement it.
     */
    public function testTheSubjectIsMatchedExactly(): void
    {
        $this->identity('bob@example.com', 'google', 'Sub-ABC');
        $this->em->flush();

        self::assertNotNull($this->repository->findOneByProviderAndSubject('google', 'Sub-ABC'));
    }

    public function testAnUnknownSubjectReturnsNull(): void
    {
        self::assertNull($this->repository->findOneByProviderAndSubject('google', 'nobody'));
    }
}
