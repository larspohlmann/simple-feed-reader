<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\FeedDirectoryScopeRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StoredUserSettings;

final class FeedDirectoryScopeRepositoryTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    public function testItReturnsTheUsersOwnRowAndNoOneElses(): void
    {
        $changed = $this->user('changed@example.test');
        $untouched = $this->user('untouched@example.test');
        $this->repository()->forUser($changed)->change(['DE'], ['de']);
        $this->entityManager->flush();

        self::assertSame(['DE'], $this->repository()->forUser($this->reload($changed))->getCountries());
        self::assertSame([], $this->repository()->forUser($this->reload($untouched))->getCountries());
    }

    public function testAUserWhoseRowIsGoneIsCorruption(): void
    {
        $user = $this->user('orphan@example.test');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_feed_directory_scope');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            \sprintf('User %d has no App\\Entity\\FeedDirectoryScope row', $user->requireId()),
        );

        $this->repository()->forUser($this->reload($user));
    }

    private function repository(): FeedDirectoryScopeRepository
    {
        return StoredUserSettings::feedDirectoryScopeRepository($this->entityManager);
    }
}
