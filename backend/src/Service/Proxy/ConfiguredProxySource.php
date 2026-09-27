<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\ProxyConfig;

interface ConfiguredProxySource
{
    /**
     * The saved proxy whether or not feed egress is switched on: the tester and proxied mail read this.
     *
     * @throws SecretUnreadableException when its stored password cannot be opened
     */
    public function configuredProxy(): ?ProxyConfig;
}
