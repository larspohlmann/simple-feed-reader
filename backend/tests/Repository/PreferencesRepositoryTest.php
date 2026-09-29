<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Preferences;
use App\Entity\User;
use App\Repository\PreferencesRepository;
use App\Tests\DbTestCase;

/**
 * Runs findWithDigestEnabled()'s real query: SendDueDigestsTest stubs the repository, so a wrong column or an
 * inverted boolean in it would pass every other test.
 */
final class PreferencesRepositoryTest extends DbTestCase
{
    private function repository(): PreferencesRepository
    {
        $repository = $this->entityManager->getRepository(Preferences::class);
        self::assertInstanceOf(PreferencesRepository::class, $repository);

        return $repository;
    }

    public function testFindWithDigestEnabledReturnsOnlyEnabledRows(): void
    {
        $enabledUser = new User('digest-on@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $enabledUser->getPreferences()->setDigestEnabled(true);

        $disabledUser = new User('digest-off@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        // digestEnabled defaults to false: left untouched on purpose.

        $this->entityManager->persist($enabledUser);
        $this->entityManager->persist($disabledUser);
        $this->entityManager->flush();

        $rows = $this->repository()->findWithDigestEnabled();

        self::assertCount(1, $rows);
        self::assertSame($enabledUser->getEmail(), $rows[0]->getUser()->getEmail());
        self::assertTrue($rows[0]->isDigestEnabled());
    }
}
