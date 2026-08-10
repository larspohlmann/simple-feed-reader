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
 * Anything that is not a transport error at all — a real HTTP status, an
 * oversized body — is a genuine answer on a live connection and never retried.
 */
final class CrossFamilyFailover
{
    public static function isWarranted(?\Throwable $transportError): bool
    {
        return $transportError instanceof TransportExceptionInterface
            && !$transportError instanceof TimeoutExceptionInterface;
    }
}
