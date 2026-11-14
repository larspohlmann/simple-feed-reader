<?php

declare(strict_types=1);

namespace App\Service\Outbound\Proxy\StoredProxySettings;

use App\Entity\ProxyServerSettings;

interface StoredProxySettingsInterface
{
    public function findSingleton(): ?ProxyServerSettings;
}
