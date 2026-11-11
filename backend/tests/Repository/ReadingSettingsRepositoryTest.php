<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\MagazineStyle;
use App\Repository\ReadingSettingsRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StoredUserSettings;

final class ReadingSettingsRepositoryTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    public function testItReturnsTheUsersOwnRowAndNoOneElses(): void
    {
        $changed = $this->user('changed@example.test');
        $untouched = $this->user('untouched@example.test');
        $this->repository()->forUser($changed)->setMagazineStyle(MagazineStyle::Airy);
        $this->entityManager->flush();

        $changedSettings = $this->repository()->forUser($this->reload($changed));
        $untouchedSettings = $this->repository()->forUser($this->reload($untouched));
        self::assertSame(MagazineStyle::Airy, $changedSettings->getMagazineStyle());
        self::assertSame(MagazineStyle::Boxed, $untouchedSettings->getMagazineStyle());
    }

    public function testAUserWhoseRowIsGoneIsCorruption(): void
    {
        $user = $this->user('orphan@example.test');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_reading_settings');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('User %d has no App\\Entity\\ReadingSettings row', $user->requireId()));

        $this->repository()->forUser($this->reload($user));
    }

    private function repository(): ReadingSettingsRepository
    {
        return StoredUserSettings::readingRepository($this->entityManager);
    }
}
