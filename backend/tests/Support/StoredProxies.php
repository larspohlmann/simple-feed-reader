<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ProxyServerSettings;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Proxy\ConfiguredProxySource\StoredProxy;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;

trait StoredProxies
{
    private function storedProxyOver(?ProxyServerSettings $row, ProxyPasswordCipher $cipher): StoredProxy
    {
        $repository = $this->createStub(ProxyServerSettingsRepository::class);
        $repository->method('findSingleton')->willReturn($row);

        return new StoredProxy($repository, $cipher);
    }
}
