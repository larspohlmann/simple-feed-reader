<?php

declare(strict_types=1);

namespace App\Service\Operations\Grafana\StoredGrafanaSettings;

use App\Entity\GrafanaSettings;

interface StoredGrafanaSettingsInterface
{
    public function findSingleton(): ?GrafanaSettings;
}
