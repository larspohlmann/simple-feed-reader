<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\DigestSettingsRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StoredUserSettings;

/**
 * Runs findWithDigestEnabled()'s real query: SendDueDigestsTest stubs the repository, so a wrong column or an
 * inverted boolean in it would pass every other test.
 */
final class DigestSettingsRepositoryTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    public function testFindWithDigestEnabledReturnsOnlyEnabledRows(): void
    {
        $enabledUser = $this->user('digest-on@example.com');
        $this->user('digest-off@example.com');
        $this->repository()->forUser($enabledUser)->setEnabled(true);
        $this->entityManager->flush();

        $rows = $this->repository()->findWithDigestEnabled();

        self::assertCount(1, $rows);
        self::assertSame($enabledUser->getEmail(), $rows[0]->getUser()->getEmail());
        self::assertTrue($rows[0]->isEnabled());
    }

    public function testTheRecipientsUsersAreFetchedWithTheirRows(): void
    {
        $user = $this->user('joined@example.com');
        $this->repository()->forUser($user)->setEnabled(true);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $rows = $this->repository()->findWithDigestEnabled();

        self::assertCount(1, $rows);
        self::assertFalse($this->entityManager->getUnitOfWork()->isUninitializedObject($rows[0]->getUser()));
    }

    public function testItReturnsTheUsersOwnRowAndNoOneElses(): void
    {
        $changed = $this->user('changed@example.test');
        $untouched = $this->user('untouched@example.test');
        $this->repository()->forUser($changed)->setEnabled(true);
        $this->entityManager->flush();

        $changedSettings = $this->repository()->forUser($this->reload($changed));
        $untouchedSettings = $this->repository()->forUser($this->reload($untouched));
        self::assertTrue($changedSettings->isEnabled());
        self::assertFalse($untouchedSettings->isEnabled());
    }

    public function testAUserWhoseRowIsGoneIsCorruption(): void
    {
        $user = $this->user('orphan@example.test');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_digest_settings');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('User %d has no App\\Entity\\DigestSettings row', $user->requireId()));

        $this->repository()->forUser($this->reload($user));
    }

    private function repository(): DigestSettingsRepository
    {
        return StoredUserSettings::digestRepository($this->entityManager);
    }
}
