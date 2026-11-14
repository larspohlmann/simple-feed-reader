<?php

declare(strict_types=1);

namespace App\Service\Outbound\Proxy\ConfiguredProxySource;

use App\Service\Foundation\Crypto\Exception\SecretUnreadableException;
use App\Service\Outbound\Fetch\Model\ProxyConfigModel;

interface ConfiguredProxySourceInterface
{
    /**
     * The saved proxy whether or not feed egress is switched on.
     *
     * @throws SecretUnreadableException when its stored password cannot be opened
     */
    public function configuredProxy(): ?ProxyConfigModel;
}
