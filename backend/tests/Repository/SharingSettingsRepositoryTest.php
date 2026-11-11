<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\SharingSettingsRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StoredUserSettings;

final class SharingSettingsRepositoryTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    public function testItReturnsTheUsersOwnRowAndNoOneElses(): void
    {
        $changed = $this->user('changed@example.test');
        $untouched = $this->user('untouched@example.test');
        $this->repository()->forUser($changed)->setEnabled(false);
        $this->entityManager->flush();

        $changedSettings = $this->repository()->forUser($this->reload($changed));
        $untouchedSettings = $this->repository()->forUser($this->reload($untouched));
        self::assertFalse($changedSettings->isEnabled());
        self::assertTrue($untouchedSettings->isEnabled());
    }

    public function testAUserWhoseRowIsGoneIsCorruption(): void
    {
        $user = $this->user('orphan@example.test');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_sharing_settings');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('User %d has no App\\Entity\\SharingSettings row', $user->requireId()));

        $this->repository()->forUser($this->reload($user));
    }

    private function repository(): SharingSettingsRepository
    {
        return StoredUserSettings::sharingRepository($this->entityManager);
    }
}
