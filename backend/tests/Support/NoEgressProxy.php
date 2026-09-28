<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;

trait NoEgressProxy
{
    private function noEgressProxy(): EgressProxySourceInterface
    {
        $egressProxySource = $this->createStub(EgressProxySourceInterface::class);
        $egressProxySource->method('egressProxy')->willReturn(null);

        return $egressProxySource;
    }
}
