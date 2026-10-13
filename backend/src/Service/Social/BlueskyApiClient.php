<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Support\NetworkAnswer;

final readonly class BlueskyApiClient
{
    private const string NETWORK = 'Bluesky';

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
        return NetworkAnswer::orUnreachable(fn (): ApiResponseModel => $this->api->send($request), self::NETWORK);
    }

    /** @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned */
    public static function answerOf(FetchOutcomeModel $outcome): ApiResponseModel
    {
        return NetworkAnswer::orUnreachable($outcome->answerOrThrow(...), self::NETWORK);
    }
}
