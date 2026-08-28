<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Preferences;
use App\Entity\User;
use App\Repository\PreferencesRepository;
use App\Tests\DbTestCase;

/**
 * findWithDigestEnabled() is the one DQL statement SendDueDigestsTest cannot
 * exercise — that test stubs the repository entirely, so a wrong column, an
 * inverted boolean, or a DBAL boolean-literal bug in the query itself would
 * pass the whole suite silently (#636). This test runs the real query
 * against a real EntityManager instead.
 */
final class PreferencesRepositoryTest extends DbTestCase
{
    private function repo(): PreferencesRepository
    {
        $repo = $this->em->getRepository(Preferences::class);
        self::assertInstanceOf(PreferencesRepository::class, $repo);

        return $repo;
    }

    public function testFindWithDigestEnabledReturnsOnlyEnabledRows(): void
    {
        $enabledUser = new User('digest-on@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $enabledUser->getPreferences()->setDigestEnabled(true);

        $disabledUser = new User('digest-off@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        // digestEnabled defaults to false: left untouched on purpose.

        $this->em->persist($enabledUser);
        $this->em->persist($disabledUser);
        $this->em->flush();

        $rows = $this->repo()->findWithDigestEnabled();

        self::assertCount(1, $rows);
        self::assertSame($enabledUser->getEmail(), $rows[0]->getUser()->getEmail());
        self::assertTrue($rows[0]->isDigestEnabled());
    }
}
