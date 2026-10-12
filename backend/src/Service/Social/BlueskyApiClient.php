<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;

final readonly class BlueskyApiClient
{
    public function __construct(private GuardedApiClient $api)
    {
    }

    /** @throws SocialNetworkUnreachableException on anything but a 2xx answer */
    public function send(ApiRequestModel $request): ApiResponseModel
    {
        $answer = $this->answer($request);
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
            throw new SocialNetworkUnreachableException('Bluesky could not be reached.', previous: $exception);
        }
    }
}
