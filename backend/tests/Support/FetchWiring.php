<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\CrossFamilyFailover;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\FetchRetryPolicy;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use App\Http\SymfonyStatusReasonPhrases;
use App\Service\Reader\LandingChallenge;
use App\Service\Reader\PageLanding;
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

    public static function guardedApiClient(
        HttpClientInterface $client,
        UrlGuard $urlGuard,
        ?ProxyConfigModel $proxy = null,
    ): GuardedApiClient {
        $egressProxy = new class ($proxy) implements EgressProxySourceInterface {
            public function __construct(private readonly ?ProxyConfigModel $proxy)
            {
            }

            public function egressProxy(): ?ProxyConfigModel
            {
                return $this->proxy;
            }
        };

        return new GuardedApiClient(self::failoverSender($client, $egressProxy), $urlGuard, 'TestAgent/1.0');
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

    public static function pageLanding(RedirectFollower $redirects): PageLanding
    {
        return new PageLanding($redirects, new LandingChallenge(), 'TestAgent/1.0', new SymfonyStatusReasonPhrases());
    }
}
