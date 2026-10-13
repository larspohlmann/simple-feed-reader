<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;

final readonly class BlueskyApiClient
{
    public function __construct(private GuardedApiClient $api)
    {
    }

    /**
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException on anything but a 2xx answer
     */
    public function send(ApiRequestModel $request): ApiResponseModel
    {
        return self::accepted($this->answer($request));
    }

    /**
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException on anything but a 2xx answer
     */
    public static function accepted(ApiResponseModel $answer): ApiResponseModel
    {
        SocialNetworkThrottledException::throwIfThrottled($answer, 'Bluesky asked for fewer requests.');
        if (!$answer->isSuccessful()) {
            throw new SocialNetworkUnreachableException(sprintf('Bluesky answered %d.', $answer->status));
        }

        return $answer;
    }

    /** @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned */
    public function answer(ApiRequestModel $request): ApiResponseModel
    {
        try {
            return $this->api->send($request);
        } catch (FetchException $exception) {
            throw self::unreachable($exception);
        }
    }

    /** @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned */
    public static function answerOf(FetchOutcomeModel $outcome): ApiResponseModel
    {
        try {
            return $outcome->answerOrThrow();
        } catch (FetchException $exception) {
            throw self::unreachable($exception);
        }
    }

    private static function unreachable(FetchException $exception): SocialNetworkUnreachableException
    {
        return new SocialNetworkUnreachableException('Bluesky could not be reached.', previous: $exception);
    }
}
