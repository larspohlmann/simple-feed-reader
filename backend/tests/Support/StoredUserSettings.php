<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ReadingSettings;
use App\Entity\User;
use App\Repository\ReadingSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

final class StoredUserSettings
{
    public static function reading(EntityManagerInterface $entityManager, User $user): ReadingSettings
    {
        $repository = $entityManager->getRepository(ReadingSettings::class);
        Assert::assertInstanceOf(ReadingSettingsRepository::class, $repository);

        return $repository->forUser($user);
    }

    private function __construct()
    {
    }
}
