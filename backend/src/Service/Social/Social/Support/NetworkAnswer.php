<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialWriteOutcomeUnknownException;

final class NetworkAnswer
{
    private function __construct()
    {
    }

    /**
     * @param \Closure(): ApiResponseModel $answer
     * @param string                       $network as the message names it: "Bluesky", "The Mastodon instance"
     *
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public static function askNetwork(\Closure $answer, string $network): ApiResponseModel
    {
        try {
            return $answer();
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException(
                sprintf('%s could not be reached.', $network),
                previous: $exception,
            );
        }
    }

    /**
     * @param \Closure(ApiRequestModel): ApiResponseModel $send
     * @param string                                      $network     as the message names it: "Bluesky"
     * @param string                                      $serviceName as a failure reports it: the host, or "Bluesky"
     *
     * @throws SocialWriteOutcomeUnknownException when a write timed out, so the network may have acted on it
     * @throws SocialNetworkUnreachableException  when no answer arrived for any other reason
     */
    public static function fetchAnswer(
        ApiRequestModel $request,
        \Closure $send,
        string $network,
        string $serviceName,
    ): ApiResponseModel {
        try {
            return $send($request);
        } catch (FetchException $exception) {
            throw $exception->isTimeout() && !$request->isRead()
                ? SocialWriteOutcomeUnknownException::unanswered($serviceName, $exception)
                : new SocialNetworkUnreachableException(
                    sprintf('%s could not be reached.', $network),
                    previous: $exception,
                );
        }
    }
}
