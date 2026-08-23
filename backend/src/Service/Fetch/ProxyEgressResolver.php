<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Proxy\ProxySettings;

/**
 * The single seam both fetch builders consult for the instance egress proxy.
 * Global: it takes no Feed, so a builder resolves it once per operation. Wrapping
 * ProxySettings here keeps ConcurrentFeedFetcher and FailoverRequestSender from
 * reaching into the settings/DTO/JSON surface directly.
 *
 * Non-final so the fetch builders' tests can stub this seam (PHPUnit cannot
 * double final classes); readonly preserves immutability.
 */
readonly class ProxyEgressResolver
{
    public function __construct(private ProxySettings $settings)
    {
    }

    public function resolve(): ?ProxyConfig
    {
        return $this->settings->egressProxy();
    }
}
