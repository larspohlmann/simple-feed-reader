<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Entity\ProxyConnection;
use App\Service\Crypto\SecretChange;

final readonly class ProxySettingsUpdate
{
    public function __construct(
        public ProxyConnection $connection,
        public SecretChange $password,
    ) {
    }
}
