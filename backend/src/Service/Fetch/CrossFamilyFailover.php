<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The one policy both fetch paths consult to decide whether a failed request is
 * worth re-driving over the next address family.
 *
 * Only a transport failure that struck the route itself qualifies — a family
 * that connected and then reset (heise's IPv6 from Strato dies at the TLS
 * handshake). A timeout is excluded on purpose: it means the family answered the
 * connect but is slow, so re-driving every family would only multiply the wait.
 * A client or server error status is judged separately (isRetryableStatus): a
 * 403 or 503 can be tied to the source address (taz.de forbids its IPv6 range
 * from Strato while IPv4 serves the page), so the other family is worth a try.
 */
final class CrossFamilyFailover
{
    public static function isWarranted(?\Throwable $transportError): bool
    {
        return $transportError instanceof TransportExceptionInterface
            && !$transportError instanceof TimeoutExceptionInterface;
    }

    /**
     * A 4xx or 5xx answer, which the other address family may not return. 2xx and
     * 304 are successes and 3xx is a redirect the caller follows, so none of those
     * is a status to route around.
     */
    public static function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode >= 400;
    }
}
