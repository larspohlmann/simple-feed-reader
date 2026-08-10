<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends a guard-validated request and fails over across address families when a
 * family cannot serve the request.
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
    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param array<string, mixed> $options request options; any `resolve` is
     *                                       overridden per family attempt
     *
     * @throws TransportExceptionInterface when the final family's connection fails
     */
    public function send(string $method, string $url, GuardedUrl $guarded, array $options): ResponseInterface
    {
        $attempts = $guarded->pinnedAddressAttempts();
        $finalAttempt = \count($attempts) - 1;

        foreach ($attempts as $index => $pinnedAddresses) {
            $response = $this->httpClient->request($method, $url, [
                ...$options,
                'resolve' => [$guarded->host => $pinnedAddresses],
                ...CrossFamilyFailover::freshConnectionAfter($index),
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
