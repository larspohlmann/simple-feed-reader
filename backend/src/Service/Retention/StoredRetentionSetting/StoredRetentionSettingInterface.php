<?php

declare(strict_types=1);

namespace App\Service\Retention\StoredRetentionSetting;

use App\Entity\RetentionSetting;

interface StoredRetentionSettingInterface
{
    public function findSingleton(): ?RetentionSetting;
}
