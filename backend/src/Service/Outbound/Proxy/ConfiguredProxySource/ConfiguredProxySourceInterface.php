<?php

declare(strict_types=1);

namespace App\Service\Proxy\ConfiguredProxySource;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\Model\ProxyConfigModel;

interface ConfiguredProxySourceInterface
{
    /**
     * The saved proxy whether or not feed egress is switched on.
     *
     * @throws SecretUnreadableException when its stored password cannot be opened
     */
    public function configuredProxy(): ?ProxyConfigModel;
}
