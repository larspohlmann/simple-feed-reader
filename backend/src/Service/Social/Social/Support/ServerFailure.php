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
     * @throws SocialWriteOutcomeUnknownException when a write got a 5xx
     * @throws SocialNetworkUnavailableException  when a read got a 5xx
     */
    public static function throwIfServerError(ApiRequestModel $request, ApiResponseModel $answer): void
    {
        if ($answer->status < Response::HTTP_INTERNAL_SERVER_ERROR) {
            return;
        }
        $host = (string) parse_url($request->url, PHP_URL_HOST);

        throw 'GET' === $request->method
            ? SocialNetworkUnavailableException::fromHost($host)
            : SocialWriteOutcomeUnknownException::fromHost($host);
    }
}
