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
        return self::readingRepository($entityManager)->forUser($user);
    }

    public static function sharing(EntityManagerInterface $entityManager, User $user): SharingSettings
    {
        return self::sharingRepository($entityManager)->forUser($user);
    }

    public static function digest(EntityManagerInterface $entityManager, User $user): DigestSettings
    {
        return self::digestRepository($entityManager)->forUser($user);
    }

    public static function readingRepository(EntityManagerInterface $entityManager): ReadingSettingsRepository
    {
        return self::repository($entityManager, ReadingSettings::class, ReadingSettingsRepository::class);
    }

    public static function sharingRepository(EntityManagerInterface $entityManager): SharingSettingsRepository
    {
        return self::repository($entityManager, SharingSettings::class, SharingSettingsRepository::class);
    }

    public static function digestRepository(EntityManagerInterface $entityManager): DigestSettingsRepository
    {
        return self::repository($entityManager, DigestSettings::class, DigestSettingsRepository::class);
    }

    /**
     * @template TRepository of object
     *
     * @param class-string                $settingsClass
     * @param class-string<TRepository>   $repositoryClass
     *
     * @return TRepository
     */
    private static function repository(
        EntityManagerInterface $entityManager,
        string $settingsClass,
        string $repositoryClass,
    ): object {
        $repository = $entityManager->getRepository($settingsClass);
        Assert::assertInstanceOf($repositoryClass, $repository);

        return $repository;
    }

    private function __construct()
    {
    }
}
