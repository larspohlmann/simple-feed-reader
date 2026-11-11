<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SharingSettings;
use App\Entity\User;
use App\Repository\SharingSettingsRepository;
use App\Tests\DbTestCase;

final class SharingSettingsRepositoryTest extends DbTestCase
{
    public function testItReturnsTheUsersOwnRowAndNoOneElses(): void
    {
        $changed = $this->persistedUser('changed@example.test');
        $untouched = $this->persistedUser('untouched@example.test');
        $this->repository()->forUser($changed)->setEnabled(false);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $changedSettings = $this->repository()->forUser($this->reload($changed));
        $untouchedSettings = $this->repository()->forUser($this->reload($untouched));
        self::assertFalse($changedSettings->isEnabled());
        self::assertTrue($untouchedSettings->isEnabled());
    }

    public function testAUserWhoseRowIsGoneIsCorruption(): void
    {
        $user = $this->persistedUser('orphan@example.test');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_sharing_settings');
        $this->entityManager->clear();

        $this->expectException(\LogicException::class);

        $this->repository()->forUser($this->reload($user));
    }

    private function persistedUser(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-11-11 09:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function reload(User $user): User
    {
        $reloaded = $this->entityManager->find(User::class, $user->requireId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    private function repository(): SharingSettingsRepository
    {
        $repository = $this->entityManager->getRepository(SharingSettings::class);
        self::assertInstanceOf(SharingSettingsRepository::class, $repository);

        return $repository;
    }
}
