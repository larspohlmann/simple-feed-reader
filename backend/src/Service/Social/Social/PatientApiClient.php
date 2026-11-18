<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Service\Outbound\Fetch\GuardedApiClient;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Support\RetryAfterHeader;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class PatientApiClient
{
    private const array PAUSES_SECONDS = [1, 2];
    private const int LONGEST_HONOURED_RETRY_AFTER_SECONDS = 3;
    private const array OUTAGE_STATUSES = [
        Response::HTTP_BAD_GATEWAY,
        Response::HTTP_SERVICE_UNAVAILABLE,
        Response::HTTP_GATEWAY_TIMEOUT,
    ];

    public function __construct(private GuardedApiClient $api, private ClockInterface $clock)
    {
    }

    public function send(ApiRequestModel $request): ApiResponseModel
    {
        $response = $this->api->send($request);
        if (!$request->isRead()) {
            return $response;
        }

        foreach (self::PAUSES_SECONDS as $pause) {
            if (!\in_array($response->status, self::OUTAGE_STATUSES, true)) {
                break;
            }
            $seconds = $this->pauseFor($response, $pause);
            if (null === $seconds) {
                break;
            }
            $this->clock->sleep($seconds);
            $response = $this->api->send($request);
        }

        return $response;
    }

    private function pauseFor(ApiResponseModel $response, int $defaultSeconds): ?int
    {
        $retryAfter = RetryAfterHeader::secondsFrom($response->header('Retry-After'), $this->clock->now());
        if (null === $retryAfter) {
            return $defaultSeconds;
        }

        return $retryAfter <= self::LONGEST_HONOURED_RETRY_AFTER_SECONDS ? $retryAfter : null;
    }
}
