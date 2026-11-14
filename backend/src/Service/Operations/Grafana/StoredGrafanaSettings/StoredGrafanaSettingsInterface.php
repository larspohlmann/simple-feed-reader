<?php

declare(strict_types=1);

namespace App\Service\Grafana\StoredGrafanaSettings;

use App\Entity\GrafanaSettings;

interface StoredGrafanaSettingsInterface
{
    public function findSingleton(): ?GrafanaSettings;
}
