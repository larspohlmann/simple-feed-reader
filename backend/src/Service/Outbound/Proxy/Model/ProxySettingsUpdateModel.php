<?php

declare(strict_types=1);

namespace App\Service\Outbound\Proxy\Model;

use App\Entity\ProxyConnection;
use App\Service\Foundation\Crypto\Model\SecretChangeModel;

final readonly class ProxySettingsUpdateModel
{
    public function __construct(
        public ProxyConnection $connection,
        public SecretChangeModel $password,
    ) {
    }
}
