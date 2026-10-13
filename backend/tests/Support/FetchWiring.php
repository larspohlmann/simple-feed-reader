<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\CrossFamilyFailover;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\FetchRetryPolicy;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\ResponseExchange\ApiResponseExchange;
use App\Service\Fetch\ResponseExchange\FeedResponseExchange;
use App\Service\Fetch\UrlGuard;
use App\Http\SymfonyStatusReasonPhrases;
use App\Service\Reader\LandingChallenge;
use App\Service\Reader\PageLanding;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
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
        return new GuardedApiClient(
            self::failoverSender($client, self::egressProxy($proxy)),
            $urlGuard,
            'TestAgent/1.0',
        );
    }

    public static function egressProxy(?ProxyConfigModel $proxy): EgressProxySourceInterface
    {
        return new class ($proxy) implements EgressProxySourceInterface {
            public function __construct(private readonly ?ProxyConfigModel $proxy)
            {
            }

            public function egressProxy(): ?ProxyConfigModel
            {
                return $this->proxy;
            }
        };
    }

    public static function retryPolicy(UrlGuard $urlGuard): FetchRetryPolicy
    {
        return new FetchRetryPolicy($urlGuard, new CrossFamilyFailover());
    }

    /** The engine as the container wires it, with every exchange keyed by its kind. */
    public static function requestEngine(
        HttpClientInterface $client,
        UrlGuard $urlGuard,
        EgressProxySourceInterface $egressProxy,
        int $concurrency = 4,
        ClockInterface $clock = new MockClock(),
    ): ConcurrentRequestEngine {
        $retryPolicy = self::retryPolicy($urlGuard);

        return new ConcurrentRequestEngine(
            $client,
            $urlGuard,
            $egressProxy,
            $retryPolicy,
            new ServiceLocator([
                FeedResponseExchange::kind() => static fn (): FeedResponseExchange => new FeedResponseExchange(
                    new ResponseClassifier($clock),
                ),
                ApiResponseExchange::kind() => static fn (): ApiResponseExchange => new ApiResponseExchange(
                    $retryPolicy,
                    new CrossFamilyFailover(),
                ),
            ]),
            $concurrency,
            'TestAgent/1.0',
        );
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
