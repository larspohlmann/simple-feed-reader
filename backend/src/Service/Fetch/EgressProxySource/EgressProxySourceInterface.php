<?php

declare(strict_types=1);

namespace App\Service\Fetch\EgressProxySource;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\ProxyConfig;

interface EgressProxySourceInterface
{
    /** @throws SecretUnreadableException when the enabled proxy's stored password cannot be opened */
    public function egressProxy(): ?ProxyConfig;
}
