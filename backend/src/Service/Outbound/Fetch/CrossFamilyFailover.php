<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch;

use App\Service\Outbound\Fetch\Model\RepeatSafety;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Whether a failed request is worth re-driving over the next address family; both fetch paths ask it. A route failure
 * such as a reset mid-handshake qualifies. A timeout does not: the family is only slow.
 */
final readonly class CrossFamilyFailover
{
    private const array ROUTE_INDEPENDENT_STATUSES = [
        Response::HTTP_NOT_FOUND,
        Response::HTTP_GONE,
        Response::HTTP_TOO_MANY_REQUESTS,
    ];

    public function allowsRetryAfterFailure(
        RepeatSafety $repeatSafety,
        ResponseInterface $failed,
        TransportExceptionInterface $transportError,
    ): bool {
        return $this->isWarranted($transportError)
            && (RepeatSafety::Repeatable === $repeatSafety || $this->hasSentNothing($failed));
    }

    public function isPossiblyDelivered(
        RepeatSafety $repeatSafety,
        ResponseInterface $failed,
        TransportExceptionInterface $transportError,
    ): bool {
        return $this->isWarranted($transportError)
            && RepeatSafety::Write === $repeatSafety
            && !$this->hasSentNothing($failed);
    }

    public function allowsRetryAfterStatus(RepeatSafety $repeatSafety, int $statusCode): bool
    {
        return RepeatSafety::Repeatable === $repeatSafety && $this->isRetryableStatus($statusCode);
    }

    public function isWarranted(?\Throwable $transportError): bool
    {
        return $transportError instanceof TransportExceptionInterface
            && !$transportError instanceof TimeoutExceptionInterface;
    }

    /**
     * A 4xx or 5xx can be tied to the source address; a missing resource cannot, and a 429 rations the client on any
     * route.
     */
    public function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode >= Response::HTTP_BAD_REQUEST
            && !\in_array($statusCode, self::ROUTE_INDEPENDENT_STATUSES, true);
    }

    private function hasSentNothing(ResponseInterface $failed): bool
    {
        return 0 === $failed->getInfo('request_size');
    }
}
