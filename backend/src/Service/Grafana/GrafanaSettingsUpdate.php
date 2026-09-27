<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Service\Crypto\SecretChange;

final readonly class GrafanaSettingsUpdate
{
    public function __construct(
        public GrafanaConnection $connection,
        public SecretChange $token,
    ) {
    }
}
