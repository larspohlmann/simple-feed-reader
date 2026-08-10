<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends a guard-validated request and fails over across address families when a
 * family connects but then dies before the response headers arrive.
 *
 * The client's own happy-eyeballs races the two families only at the TCP
 * connect; once one connects it is committed, so a family that resets during
 * the TLS handshake (heise's IPv6 from Strato) takes the whole request down
 * with no fallback. This sender pins each family in turn and forces the headers
 * to arrive, so that transport failure surfaces here and the next family is
 * tried. A real HTTP status — even 4xx or 5xx — is a genuine answer and is
 * returned untouched.
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
     * @throws TransportExceptionInterface when every family attempt fails
     */
    public function send(string $method, string $url, GuardedUrl $guarded, array $options): ResponseInterface
    {
        $attempts = $guarded->pinnedAddressAttempts();
        $lastError = null;

        foreach ($attempts as $pinnedAddresses) {
            $response = $this->httpClient->request($method, $url, [
                ...$options,
                'resolve' => [$guarded->host => $pinnedAddresses],
            ]);

            try {
                // Block until the status line arrives: a family that resets after
                // the TCP connect throws here, so the next family can be tried.
                $response->getStatusCode();

                return $response;
            } catch (TransportExceptionInterface $transportError) {
                $response->cancel();
                if (!CrossFamilyFailover::isWarranted($transportError)) {
                    // A timeout, not a dead route: another family would only add
                    // more waiting. Surface it now rather than retrying.
                    throw $transportError;
                }
                $lastError = $transportError;
            }
        }

        // The attempt list is non-empty and every family reset, so $lastError
        // holds the final dead-route error.
        throw $lastError;
    }
}
