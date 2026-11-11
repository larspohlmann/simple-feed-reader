<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\DigestSettings;
use App\Entity\FeedDirectoryScope;
use App\Entity\ReadingSettings;
use App\Entity\SharingSettings;
use App\Entity\User;
use App\Tests\DbTestCase;
use App\Tests\Support\StoredUserSettings;

final class UserSettingsRowsListenerTest extends DbTestCase
{
    public function testAPersistedAccountGetsItsSettingsRowsInTheSameFlush(): void
    {
        $user = new User('rows@example.test', new \DateTimeImmutable('2026-11-11 09:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(User::class, $user->requireId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertFalse(StoredUserSettings::reading($this->entityManager, $reloaded)->isScrapeFallbackEnabled());
        self::assertTrue(StoredUserSettings::sharing($this->entityManager, $reloaded)->isEnabled());
        self::assertFalse(StoredUserSettings::digest($this->entityManager, $reloaded)->isEnabled());
        self::assertSame([], StoredUserSettings::feedDirectoryScope($this->entityManager, $reloaded)->getCountries());
    }

    public function testPersistingAManagedAccountAgainAddsNoSecondRow(): void
    {
        $user = new User('once@example.test', new \DateTimeImmutable('2026-11-11 09:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $user->setLocale('de');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        self::assertSame(1, $this->entityManager->getRepository(ReadingSettings::class)->count(['user' => $user]));
        self::assertSame(1, $this->entityManager->getRepository(SharingSettings::class)->count(['user' => $user]));
        self::assertSame(1, $this->entityManager->getRepository(DigestSettings::class)->count(['user' => $user]));
        self::assertSame(1, $this->entityManager->getRepository(FeedDirectoryScope::class)->count(['user' => $user]));
    }
}
