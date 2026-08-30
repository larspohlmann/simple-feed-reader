<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserPasskeyTest extends DbTestCase
{
    public function testACredentialRoundTripsThroughTheDatabase(): void
    {
        $user = $this->user('passkey-owner@example.test');
        $passkey = new UserPasskey(
            $user,
            'Y3JlZC1hYmM',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            '00000000-0000-0000-0000-000000000000',
            ['internal', 'hybrid'],
            'MacBook Touch ID',
            new \DateTimeImmutable('2026-08-29 10:00:00'),
        );
        $this->em->persist($passkey);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository()->findOneByCredentialId('Y3JlZC1hYmM');

        self::assertNotNull($found);
        self::assertSame(['internal', 'hybrid'], $found->getTransports());
        self::assertNull($found->getLastUsedAt());
    }

    /**
     * The whole reason credential_id is pinned to utf8mb4_bin. Without the
     * pin this passes on SQLite and fails on MySQL, which is exactly the
     * split that bit user_identity.provider_user_id.
     */
    public function testACredentialIdIsComparedCaseSensitively(): void
    {
        $user = $this->user('case-owner@example.test');
        $this->em->persist(new UserPasskey(
            $user,
            'Sub-ABC',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Key',
            new \DateTimeImmutable(),
        ));
        $this->em->flush();
        $this->em->clear();

        $repository = $this->repository();

        self::assertNotNull($repository->findOneByCredentialId('Sub-ABC'));
        self::assertNull($repository->findOneByCredentialId('sub-abc'));
    }

    public function testRecordUseStampsBothTheClockAndTheCounterTogether(): void
    {
        $user = $this->user('recorder@example.test');
        $passkey = new UserPasskey(
            $user,
            'Y3JlZC1yZWM',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Key',
            new \DateTimeImmutable(),
        );
        $this->em->persist($passkey);
        $this->em->flush();

        $usedAt = new \DateTimeImmutable('2026-08-29 12:00:00');
        $passkey->recordUse($usedAt, 7);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository()->findOneByCredentialId('Y3JlZC1yZWM');

        self::assertNotNull($found);
        self::assertEquals($usedAt, $found->getLastUsedAt());
        self::assertSame(7, $found->getSignatureCounter());
    }

    public function testFindOneForUserScopesTheLookupToTheOwner(): void
    {
        $owner = $this->user('owner@example.test');
        $stranger = $this->user('stranger@example.test');
        $passkey = new UserPasskey(
            $owner,
            'Y3JlZC1vd24',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Key',
            new \DateTimeImmutable(),
        );
        $this->em->persist($passkey);
        $this->em->flush();
        $id = $passkey->getId();
        self::assertNotNull($id);
        $this->em->clear();

        $repository = $this->repository();

        self::assertNotNull($repository->findOneForUser($owner, $id));
        self::assertNull($repository->findOneForUser($stranger, $id));
    }

    public function testFindForUserOrdersByCreationAscending(): void
    {
        $user = $this->user('lister@example.test');
        $older = new UserPasskey(
            $user,
            'Y3JlZC1vbGQ',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Older',
            new \DateTimeImmutable('2026-08-01 00:00:00'),
        );
        $newer = new UserPasskey(
            $user,
            'Y3JlZC1uZXc',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Newer',
            new \DateTimeImmutable('2026-08-15 00:00:00'),
        );
        $this->em->persist($newer);
        $this->em->persist($older);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository()->findForUser($user);

        self::assertCount(2, $found);
        self::assertSame('Older', $found[0]->getLabel());
        self::assertSame('Newer', $found[1]->getLabel());
    }

    public function testCountForUserCountsOnlyThatUsersCredentials(): void
    {
        $user = $this->user('counter@example.test');
        $other = $this->user('other@example.test');
        $this->em->persist(new UserPasskey(
            $user,
            'Y3JlZC1jbnQx',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'One',
            new \DateTimeImmutable(),
        ));
        $this->em->persist(new UserPasskey(
            $other,
            'Y3JlZC1jbnQy',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Two',
            new \DateTimeImmutable(),
        ));
        $this->em->flush();
        $this->em->clear();

        $repository = $this->repository();

        self::assertSame(1, $repository->countForUser($user));
        self::assertSame(2, $repository->countAll());
    }

    public function testDeleteAllRemovesEveryCredential(): void
    {
        $user = $this->user('wiper@example.test');
        $this->em->persist(new UserPasskey(
            $user,
            'Y3JlZC13aXBl',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Key',
            new \DateTimeImmutable(),
        ));
        $this->em->flush();

        $this->repository()->deleteAll();
        $this->em->clear();

        self::assertSame(0, $this->repository()->countAll());
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function repository(): UserPasskeyRepository
    {
        /** @var UserPasskeyRepository $repository */
        $repository = $this->em->getRepository(UserPasskey::class);

        return $repository;
    }
}
