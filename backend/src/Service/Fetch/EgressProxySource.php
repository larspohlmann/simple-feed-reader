<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Crypto\Exception\SecretUnreadableException;

interface EgressProxySource
{
    /** @throws SecretUnreadableException when the enabled proxy's stored password cannot be opened */
    public function egressProxy(): ?ProxyConfig;
}
