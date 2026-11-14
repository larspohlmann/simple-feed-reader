<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\EgressProxySource;

use App\Service\Foundation\Crypto\Exception\SecretUnreadableException;
use App\Service\Outbound\Fetch\Model\ProxyConfigModel;

interface EgressProxySourceInterface
{
    /** @throws SecretUnreadableException when the enabled proxy's stored password cannot be opened */
    public function egressProxy(): ?ProxyConfigModel;
}
