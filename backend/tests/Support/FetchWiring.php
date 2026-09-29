<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\CrossFamilyFailover;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\FetchRetryPolicy;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The fetch stack over the real cross-family failover, wired as the container wires it. */
final class FetchWiring
{
    private function __construct()
    {
    }

    public static function failoverSender(
        HttpClientInterface $client,
        EgressProxySourceInterface $egressProxy,
    ): FailoverRequestSender {
        return new FailoverRequestSender($client, $egressProxy, new CrossFamilyFailover());
    }

    public static function retryPolicy(UrlGuard $urlGuard): FetchRetryPolicy
    {
        return new FetchRetryPolicy($urlGuard, new CrossFamilyFailover());
    }

    public static function redirectFollower(
        HttpClientInterface $client,
        EgressProxySourceInterface $egressProxy,
        UrlGuard $urlGuard,
    ): RedirectFollower {
        return new RedirectFollower(self::failoverSender($client, $egressProxy), $urlGuard);
    }
}
