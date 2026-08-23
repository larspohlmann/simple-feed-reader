<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Proxy\Crypto\Exception\ProxyPasswordUnreadableException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends a guard-validated request and fails over across address families when a
 * family cannot serve the request.
 *
 * When a proxy is resolved, the proxied route is attempted first; the pinned
 * direct families exist only as its fallback, and only once direct fallback is
 * on and the failure is one direct can plausibly fix (§CrossFamilyFailover) --
 * a proxy is opted into to hide the real IP, so a transport failure with direct
 * fallback off is terminal rather than silently leaking that IP.
 *
 * The client's own happy-eyeballs races the two families only at the TCP
 * connect; once one connects it is committed, so a family that resets during
 * the TLS handshake (heise's IPv6 from Strato) takes the whole request down with
 * no fallback. This sender pins each family in turn and forces the status line to
 * arrive, so both a post-connect transport reset and a route-specific error
 * status (taz.de forbids its IPv6 range from Strato while IPv4 serves 200) fall
 * over to the family that works. The last family's answer stands as it is, so a
 * genuine 4xx/5xx is still reported.
 */
final readonly class FailoverRequestSender
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ProxyEgressResolver $proxyEgressResolver,
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
        $proxy = $this->resolveProxy();

        if (null !== $proxy) {
            $proxiedResponse = $this->attemptProxied($method, $url, $options, $proxy);
            if (null !== $proxiedResponse) {
                return $proxiedResponse;
            }
            // fall through to the pinned direct families
        }

        return $this->sendPinnedFamilies($method, $url, $guarded, $options);
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
    private function resolveProxy(): ?ProxyConfig
    {
        try {
            return $this->proxyEgressResolver->resolve();
        } catch (ProxyPasswordUnreadableException $e) {
            throw new TransportException(
                sprintf('The instance egress proxy is unusable: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return ResponseInterface|null the proxied answer, or null to fall
     *                                 through to a pinned direct attempt
     *
     * @throws TransportExceptionInterface when direct fallback is unavailable
     */
    private function attemptProxied(
        string $method,
        string $url,
        array $options,
        ProxyConfig $proxy,
    ): ?ResponseInterface {
        $response = $this->httpClient->request($method, $url, [...$options, ...EgressOptions::proxied($proxy)]);

        try {
            $response->getStatusCode();

            return $response;
        } catch (TransportExceptionInterface $transportError) {
            $response->cancel();
            // No direct fallback when the admin turned it off: falling back to
            // a pinned direct request would leak the real server IP.
            if (!$proxy->directFallback || !CrossFamilyFailover::isWarranted($transportError)) {
                throw $transportError;
            }

            return null;
        }
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
