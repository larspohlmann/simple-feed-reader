<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\Exception\ProxiedAttemptFailedException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends a guard-validated request over a resolved egress proxy first, falling
 * back to pinned direct families. happy-eyeballs races only at TCP connect, so a
 * post-connect TLS reset (heise) or route-specific error status (taz) needs this.
 */
final readonly class FailoverRequestSender
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EgressProxySource $egressProxySource,
    ) {
    }

    /**
     * @param array<string, mixed> $options request options; any `resolve` is
     *                                       overridden per family attempt
     *
     * @throws TransportExceptionInterface when the proxied attempt fails with no
     *                                      direct fallback (terminal, before any
     *                                      pinned family is tried), or when the
     *                                      final pinned family's connection fails
     */
    public function send(string $method, string $url, GuardedUrl $guarded, array $options): ResponseInterface
    {
        $proxy = $this->egressProxyOrFail();
        if (null === $proxy) {
            return $this->sendPinnedFamilies($method, $url, $guarded, $options);
        }

        try {
            return $this->attemptProxied($method, $url, $options, $proxy);
        } catch (ProxiedAttemptFailedException) {
            return $this->sendPinnedFamilies($method, $url, $guarded, $options);
        }
    }

    /**
     * An enabled proxy whose stored password cannot be opened is a transport
     * failure, not a reason to fall through to a direct request: falling
     * through would leak the real server IP that the proxy exists to hide. The
     * translation keeps this method's contract, so the callers' existing
     * transport-error handling reports it instead of a raw RuntimeException
     * escaping into a 500.
     *
     * @throws TransportExceptionInterface when the egress cannot be resolved
     */
    private function egressProxyOrFail(): ?ProxyConfig
    {
        try {
            return $this->egressProxySource->egressProxy();
        } catch (SecretUnreadableException $e) {
            throw new TransportException(
                sprintf('The instance egress proxy is unusable: %s', $e->getMessage()),
                previous: $e,
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
        string $method,
        string $url,
        array $options,
        ProxyConfig $proxy,
    ): ResponseInterface {
        $response = $this->httpClient->request($method, $url, [...$options, ...EgressOptions::proxied($proxy)]);

        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $transportError) {
            $response->cancel();
            // With fallback off, going direct would leak the real server IP the proxy hides.
            if (!$proxy->directFallback || !CrossFamilyFailover::isWarranted($transportError)) {
                throw $transportError;
            }

            throw new ProxiedAttemptFailedException(previous: $transportError);
        }

        // A CDN/WAF refusal of the proxy's egress IP may still be served directly, but only with fallback on.
        if ($proxy->directFallback && CrossFamilyFailover::isRetryableStatus($status)) {
            $response->cancel();

            throw new ProxiedAttemptFailedException();
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $options request options; any `resolve` is
     *                                       overridden per family attempt
     *
     * @throws TransportExceptionInterface when the final family's connection fails
     */
    private function sendPinnedFamilies(
        string $method,
        string $url,
        GuardedUrl $guarded,
        array $options,
    ): ResponseInterface {
        $attempts = $guarded->pinnedAddressAttempts();
        $finalAttempt = \count($attempts) - 1;

        foreach (array_keys($attempts) as $index) {
            $response = $this->httpClient->request($method, $url, [
                ...$options,
                ...EgressOptions::pinned($guarded, $index),
            ]);
            $canFailOver = $index < $finalAttempt;

            try {
                // Block until the status line arrives: a family that resets after
                // the TCP connect throws here.
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $transportError) {
                $response->cancel();
                if ($canFailOver && CrossFamilyFailover::isWarranted($transportError)) {
                    continue;
                }

                throw $transportError;
            }

            if ($canFailOver && CrossFamilyFailover::isRetryableStatus($status)) {
                $response->cancel();

                continue;
            }

            return $response;
        }

        throw new \LogicException('A non-empty attempt list always returns or throws on its final family.');
    }
}
