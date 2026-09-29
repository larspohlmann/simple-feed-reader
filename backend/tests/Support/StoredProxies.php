<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ProxyServerSettings;
use App\Service\Proxy\ConfiguredProxySource\StoredProxy;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\StoredProxySettings\StoredProxySettingsInterface;

trait StoredProxies
{
    private function storedProxyOver(?ProxyServerSettings $row, ProxyPasswordCipher $cipher): StoredProxy
    {
        $repository = $this->createStub(StoredProxySettingsInterface::class);
        $repository->method('findSingleton')->willReturn($row);

        return new StoredProxy($repository, $cipher);
    }
}
