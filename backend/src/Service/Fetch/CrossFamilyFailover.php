<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Whether a failed request is worth re-driving over the next address family; both fetch paths ask it. A route failure
 * such as a reset mid-handshake qualifies. A timeout does not: the family is only slow.
 */
final readonly class CrossFamilyFailover
{
    private const array MISSING_RESOURCE_STATUSES = [Response::HTTP_NOT_FOUND, Response::HTTP_GONE];

    public function isWarranted(?\Throwable $transportError): bool
    {
        return $transportError instanceof TransportExceptionInterface
            && !$transportError instanceof TimeoutExceptionInterface;
    }

    /** A 4xx or 5xx can be tied to the source address, so another route may answer differently; a missing resource cannot. */
    public function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode >= Response::HTTP_BAD_REQUEST
            && !\in_array($statusCode, self::MISSING_RESOURCE_STATUSES, true);
    }
}
