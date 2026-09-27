<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\EgressProxySource;

trait NoEgressProxy
{
    private function noEgressProxy(): EgressProxySource
    {
        $egressProxySource = $this->createStub(EgressProxySource::class);
        $egressProxySource->method('egressProxy')->willReturn(null);

        return $egressProxySource;
    }
}
