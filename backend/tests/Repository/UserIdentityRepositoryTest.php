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
     * This assertion is only meaningful on MySQL, and it is the whole reason
     * `provider_user_id` carries an explicit `utf8mb4_bin` collation. SQLite
     * has always compared it case-sensitively; MySQL inherited the table
     * default (`utf8mb4_0900_ai_ci`) and did not, so before
     * Version20260721181500 this test passed on the SQLite leg and failed on
     * the MySQL one. Both legs now agree, on MySQL's behaviour being corrected
     * to match SQLite's rather than the other way round.
     *
     * A subject identifier is an opaque token, not a name. The two providers
     * shipping today issue digits, so this never fires for them — but a
     * provider issuing base64url subjects would have `sub-abc` and `Sub-ABC` as
     * two different people, and matching them would sign the second one in as
     * the first.
     */
    public function testSubjectLookupIsCaseSensitive(): void
    {
        $this->identity('bob@example.com', 'google', 'Sub-ABC');
        $this->em->flush();

        self::assertNull($this->repository->findOneByProviderAndSubject('google', 'sub-abc'));
        self::assertNotNull($this->repository->findOneByProviderAndSubject('google', 'Sub-ABC'));
    }

    public function testAnUnknownSubjectReturnsNull(): void
    {
        self::assertNull($this->repository->findOneByProviderAndSubject('google', 'nobody'));
    }
}
