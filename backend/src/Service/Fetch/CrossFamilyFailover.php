<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The one policy both fetch paths consult to decide whether a failed request
 * is worth re-driving over the next address family.
 *
 * Only a transport failure that struck the route itself qualifies — e.g. a
 * family resetting mid-handshake (heise's IPv6 from Strato) — not a timeout,
 * which just means the family is slow, so re-driving would waste time. A
 * client/server error status is judged separately (isRetryableStatus): a
 * 403/503 can be tied to the source address (taz.de blocks IPv6 from Strato,
 * IPv4 works), so the other family is worth a try.
 */
final readonly class CrossFamilyFailover
{
    public function isWarranted(?\Throwable $transportError): bool
    {
        return $transportError instanceof TransportExceptionInterface
            && !$transportError instanceof TimeoutExceptionInterface;
    }

    /**
     * A 4xx or 5xx answer, which the other address family may not return. 2xx and
     * 304 are successes and 3xx is a redirect the caller follows, so none of those
     * is a status to route around.
     */
    public function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode >= 400;
    }
}
