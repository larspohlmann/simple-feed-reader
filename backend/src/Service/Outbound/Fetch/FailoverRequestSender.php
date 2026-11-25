<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch;

use App\Service\Foundation\Crypto\Exception\SecretUnreadableException;
use App\Service\Outbound\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Outbound\Fetch\Exception\DirectRouteUnavailableException;
use App\Service\Outbound\Fetch\Exception\ProxiedAttemptFailedException;
use App\Service\Outbound\Fetch\Model\GuardedUrlModel;
use App\Service\Outbound\Fetch\Model\ProxyConfigModel;
use App\Service\Outbound\Fetch\Support\EgressOptions;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends a guard-validated request over the egress proxy first, then over each pinned address family. Happy eyeballs
 * races only at TCP connect, so a reset after connect or a route-specific error status needs this.
 */
final readonly class FailoverRequestSender
{
    private const string REPEATABLE_METHOD = 'GET';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EgressProxySourceInterface $egressProxySource,
        private CrossFamilyFailover $failover,
    ) {
    }

    /**
     * @param array<string, mixed> $options any `resolve` is overridden per family attempt
     *
     * @throws TransportExceptionInterface when the proxied attempt fails without fallback, or the last family fails
     */
    public function send(string $method, string $url, GuardedUrlModel $guarded, array $options): ResponseInterface
    {
        return $this->sendJudgedAs($method, $method, $url, $guarded, $options);
    }

    /**
     * A request its caller knows changes nothing keeps the failover a read gets, whatever its method.
     *
     * @param array<string, mixed> $options any `resolve` is overridden per family attempt
     *
     * @throws TransportExceptionInterface when the proxied attempt fails without fallback, or the last family fails
     */
    public function sendRepeatable(
        string $method,
        string $url,
        GuardedUrlModel $guarded,
        array $options,
    ): ResponseInterface {
        return $this->sendJudgedAs(self::REPEATABLE_METHOD, $method, $url, $guarded, $options);
    }

    /**
     * @param string               $judgedMethod the method the failover policy reads; `$method` goes on the wire
     * @param array<string, mixed> $options
     *
     * @throws TransportExceptionInterface when the proxied attempt fails without fallback, or the last family fails
     */
    private function sendJudgedAs(
        string $judgedMethod,
        string $method,
        string $url,
        GuardedUrlModel $guarded,
        array $options,
    ): ResponseInterface {
        $proxy = $this->egressProxyOrFail();
        if (null === $proxy) {
            return $this->sendPinnedFamilies($judgedMethod, $method, $url, $guarded, $options);
        }

        try {
            return $this->attemptProxied($judgedMethod, $method, $url, $options, $proxy);
        } catch (ProxiedAttemptFailedException) {
            return $this->sendPinnedFamilies($judgedMethod, $method, $url, $guarded, $options);
        }
    }

    /**
     * For a proxied answer only the caller can tell is a refusal of the proxy's egress, as no status says so.
     *
     * @param array<string, mixed> $options any `resolve` is overridden per family attempt
     *
     * @throws DirectRouteUnavailableException when no proxy is configured or its direct fallback is off
     * @throws TransportExceptionInterface     when the egress cannot be resolved, or the last family fails
     */
    public function sendDirectAfterProxyRefusal(
        string $method,
        string $url,
        GuardedUrlModel $guarded,
        array $options,
    ): ResponseInterface {
        return $this->sendDirectJudgedAs($method, $method, $url, $guarded, $options);
    }

    /**
     * The direct retry of a request its caller knows changes nothing keeps the failover a read gets.
     *
     * @param array<string, mixed> $options any `resolve` is overridden per family attempt
     *
     * @throws DirectRouteUnavailableException when no proxy is configured or its direct fallback is off
     * @throws TransportExceptionInterface     when the egress cannot be resolved, or the last family fails
     */
    public function sendRepeatableDirectAfterProxyRefusal(
        string $method,
        string $url,
        GuardedUrlModel $guarded,
        array $options,
    ): ResponseInterface {
        return $this->sendDirectJudgedAs(self::REPEATABLE_METHOD, $method, $url, $guarded, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws DirectRouteUnavailableException when no proxy is configured or its direct fallback is off
     * @throws TransportExceptionInterface     when the egress cannot be resolved, or the last family fails
     */
    private function sendDirectJudgedAs(
        string $judgedMethod,
        string $method,
        string $url,
        GuardedUrlModel $guarded,
        array $options,
    ): ResponseInterface {
        $proxy = $this->egressProxyOrFail();
        if (null === $proxy) {
            throw DirectRouteUnavailableException::withoutAProxy($url);
        }
        if (!$proxy->directFallback) {
            throw DirectRouteUnavailableException::withoutFallback($url);
        }

        return $this->sendPinnedFamilies($judgedMethod, $method, $url, $guarded, $options);
    }

    /**
     * An unreadable proxy password is a transport failure, never a fall-through to a direct request, which would leak
     * the server IP the proxy hides. Callers already report transport errors, so it cannot escape as a 500.
     *
     * @throws TransportExceptionInterface when the egress cannot be resolved
     */
    private function egressProxyOrFail(): ?ProxyConfigModel
    {
        try {
            return $this->egressProxySource->egressProxy();
        } catch (SecretUnreadableException $exception) {
            throw new TransportException(
                sprintf('The instance egress proxy is unusable: %s', $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ProxiedAttemptFailedException when direct fallback is on and warranted
     * @throws TransportExceptionInterface   when direct fallback is unavailable
     */
    private function attemptProxied(
        string $judgedMethod,
        string $method,
        string $url,
        array $options,
        ProxyConfigModel $proxy,
    ): ResponseInterface {
        $response = $this->httpClient->request(
            $method,
            $url,
            EgressOptions::merge($options, EgressOptions::proxied($proxy)),
        );

        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $transportError) {
            $response->cancel();
            // With fallback off, going direct would leak the real server IP the proxy hides.
            $mayGoDirect = $proxy->directFallback
                && $this->failover->allowsRetryAfterFailure($judgedMethod, $response, $transportError);
            if (!$mayGoDirect) {
                throw $transportError;
            }

            throw new ProxiedAttemptFailedException(previous: $transportError);
        }

        // A CDN/WAF refusal of the proxy's egress IP may still be served directly, but only with fallback on.
        if ($proxy->directFallback && $this->failover->allowsRetryAfterStatus($judgedMethod, $status)) {
            $response->cancel();

            throw new ProxiedAttemptFailedException();
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws TransportExceptionInterface when the final family's connection fails
     */
    private function sendPinnedFamilies(
        string $judgedMethod,
        string $method,
        string $url,
        GuardedUrlModel $guarded,
        array $options,
    ): ResponseInterface {
        $attempts = $guarded->pinnedAddressAttempts();
        $finalAttempt = \count($attempts) - 1;

        foreach (array_keys($attempts) as $index) {
            $response = $this->httpClient->request(
                $method,
                $url,
                EgressOptions::merge($options, EgressOptions::pinned($guarded, $index)),
            );
            $canFailOver = $index < $finalAttempt;

            try {
                // Block until the status line arrives: a family that resets after
                // the TCP connect throws here.
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $transportError) {
                $response->cancel();
                if (
                    $canFailOver
                    && $this->failover->allowsRetryAfterFailure($judgedMethod, $response, $transportError)
                ) {
                    continue;
                }

                throw $transportError;
            }

            if ($canFailOver && $this->failover->allowsRetryAfterStatus($judgedMethod, $status)) {
                $response->cancel();

                continue;
            }

            return $response;
        }

        throw new \LogicException('A non-empty attempt list always returns or throws on its final family.');
    }
}
