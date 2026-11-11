<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\DigestSettings;
use App\Entity\User;
use App\Repository\DigestSettingsRepository;
use App\Tests\DbTestCase;

/**
 * Runs findWithDigestEnabled()'s real query: SendDueDigestsTest stubs the repository, so a wrong column or an
 * inverted boolean in it would pass every other test.
 */
final class DigestSettingsRepositoryTest extends DbTestCase
{
    public function testFindWithDigestEnabledReturnsOnlyEnabledRows(): void
    {
        $enabledUser = $this->persistedUser('digest-on@example.com');
        $this->persistedUser('digest-off@example.com');
        $this->repository()->forUser($enabledUser)->setEnabled(true);
        $this->entityManager->flush();

        $rows = $this->repository()->findWithDigestEnabled();

        self::assertCount(1, $rows);
        self::assertSame($enabledUser->getEmail(), $rows[0]->getUser()->getEmail());
        self::assertTrue($rows[0]->isEnabled());
    }

    public function testTheRecipientsUsersAreFetchedWithTheirRows(): void
    {
        $user = $this->persistedUser('joined@example.com');
        $this->repository()->forUser($user)->setEnabled(true);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $rows = $this->repository()->findWithDigestEnabled();

        self::assertCount(1, $rows);
        self::assertFalse($this->entityManager->getUnitOfWork()->isUninitializedObject($rows[0]->getUser()));
    }

    public function testItReturnsTheUsersOwnRowAndNoOneElses(): void
    {
        $changed = $this->persistedUser('changed@example.test');
        $untouched = $this->persistedUser('untouched@example.test');
        $this->repository()->forUser($changed)->setEnabled(true);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $changedSettings = $this->repository()->forUser($this->reload($changed));
        $untouchedSettings = $this->repository()->forUser($this->reload($untouched));
        self::assertTrue($changedSettings->isEnabled());
        self::assertFalse($untouchedSettings->isEnabled());
    }

    public function testAUserWhoseRowIsGoneIsCorruption(): void
    {
        $user = $this->persistedUser('orphan@example.test');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_digest_settings');
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

    private function repository(): DigestSettingsRepository
    {
        $repository = $this->entityManager->getRepository(DigestSettings::class);
        self::assertInstanceOf(DigestSettingsRepository::class, $repository);

        return $repository;
    }
}
