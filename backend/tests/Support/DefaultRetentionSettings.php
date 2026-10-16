<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\RetentionSetting;
use App\Service\Retention\RetentionSettings;
use App\Service\Retention\StoredRetentionSetting\StoredRetentionSettingInterface;
use Doctrine\ORM\EntityManagerInterface;

/** Retention settings with no stored row: the default windows. */
final class DefaultRetentionSettings
{
    public static function create(EntityManagerInterface $entityManager): RetentionSettings
    {
        $noStoredRow = new class implements StoredRetentionSettingInterface {
            public function findSingleton(): ?RetentionSetting
            {
                return null;
            }
        };

        return new RetentionSettings($noStoredRow, $entityManager);
    }
}
