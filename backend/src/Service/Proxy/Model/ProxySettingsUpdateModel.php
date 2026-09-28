<?php

declare(strict_types=1);

namespace App\Service\Proxy\Model;

use App\Entity\ProxyConnection;
use App\Service\Crypto\Model\SecretChangeModel;

final readonly class ProxySettingsUpdateModel
{
    public function __construct(
        public ProxyConnection $connection,
        public SecretChangeModel $password,
    ) {
    }
}
