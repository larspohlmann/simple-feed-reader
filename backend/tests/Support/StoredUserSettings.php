<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\DigestSettings;
use App\Entity\ReadingSettings;
use App\Entity\SharingSettings;
use App\Entity\User;
use App\Repository\DigestSettingsRepository;
use App\Repository\ReadingSettingsRepository;
use App\Repository\SharingSettingsRepository;
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

    public static function sharing(EntityManagerInterface $entityManager, User $user): SharingSettings
    {
        $repository = $entityManager->getRepository(SharingSettings::class);
        Assert::assertInstanceOf(SharingSettingsRepository::class, $repository);

        return $repository->forUser($user);
    }

    public static function digest(EntityManagerInterface $entityManager, User $user): DigestSettings
    {
        return self::digestRepository($entityManager)->forUser($user);
    }

    public static function digestRepository(EntityManagerInterface $entityManager): DigestSettingsRepository
    {
        $repository = $entityManager->getRepository(DigestSettings::class);
        Assert::assertInstanceOf(DigestSettingsRepository::class, $repository);

        return $repository;
    }

    private function __construct()
    {
    }
}
