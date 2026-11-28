<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Entity\YourStreamSettings;
use App\Entity\YourStreamTuning;
use App\Enum\YourStreamPrecision;
use App\Repository\YourStreamSettingsRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\RequiresServices;

final class YourStreamSettingsRepositoryTest extends DbTestCase
{
    use RequiresServices;

    public function testAnAccountWithoutARowHasTheDefaultTuning(): void
    {
        $user = $this->account('no-row@example.com');

        self::assertNull($this->settings()->findForUser($user));
        self::assertEquals(YourStreamTuning::defaults(), $this->settings()->findTuningForUser($user->requireId()));
    }

    public function testAStoredRowGivesItsTuning(): void
    {
        $user = $this->account('stored@example.com');
        $this->entityManager->persist(
            new YourStreamSettings($user, new YourStreamTuning(false, YourStreamPrecision::Narrowest)),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertEquals(
            new YourStreamTuning(false, YourStreamPrecision::Narrowest),
            $this->settings()->findTuningForUser($user->requireId()),
        );
    }

    private function account(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function settings(): YourStreamSettingsRepository
    {
        return $this->requireFromContainer(YourStreamSettingsRepository::class);
    }
}
