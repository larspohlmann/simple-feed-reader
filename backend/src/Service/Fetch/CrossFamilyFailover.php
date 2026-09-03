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

    /**
     * The `extra.curl` option that forces a failover retry onto its own
     * connection, empty on the first attempt. curl pools connections by
     * host:port, so without this a retry pinned to a new family would reuse the
     * previous family's still-open connection (taz's IPv6 answers 403 and stays
     * keep-alive) and ignore the new pin, defeating the failover.
     *
     * @return array{extra?: array{curl: array<int, bool>}}
     */
    public static function freshConnectionAfter(int $attemptIndex): array
    {
        return $attemptIndex > 0
            ? ['extra' => ['curl' => [\CURLOPT_FRESH_CONNECT => true]]]
            : [];
    }
}
