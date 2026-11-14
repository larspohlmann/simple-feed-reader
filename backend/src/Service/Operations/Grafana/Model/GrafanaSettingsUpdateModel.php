<?php

declare(strict_types=1);

namespace App\Service\Operations\Grafana\Model;

use App\Entity\GrafanaConnection;
use App\Service\Foundation\Crypto\Model\SecretChangeModel;

final readonly class GrafanaSettingsUpdateModel
{
    public function __construct(
        public GrafanaConnection $connection,
        public SecretChangeModel $token,
    ) {
    }
}
