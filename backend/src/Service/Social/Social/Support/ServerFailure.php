<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Social\Exception\SocialNetworkUnavailableException;
use App\Service\Social\Social\Exception\SocialWriteOutcomeUnknownException;
use Symfony\Component\HttpFoundation\Response;

final class ServerFailure
{
    private function __construct()
    {
    }

    /**
     * @param string $name what the message calls the failing service: the request host, or "Bluesky"
     *
     * @throws SocialWriteOutcomeUnknownException when a write got a 5xx
     * @throws SocialNetworkUnavailableException  when a read got a 5xx
     */
    public static function throwIfServerError(ApiRequestModel $request, ApiResponseModel $answer, string $name): void
    {
        if ($answer->status < Response::HTTP_INTERNAL_SERVER_ERROR) {
            return;
        }

        throw 'GET' === $request->method
            ? SocialNetworkUnavailableException::fromHost($name)
            : SocialWriteOutcomeUnknownException::fromHost($name);
    }
}
