<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use Symfony\Component\HttpFoundation\Response;

final readonly class MastodonApiClient
{
    private const array REFUSALS = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];

    public function __construct(private GuardedApiClient $api)
    {
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function send(ApiRequestModel $request): ApiResponseModel
    {
        return self::accepted($this->answer($request));
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException on any other answer but a 2xx
     */
    public static function accepted(ApiResponseModel $answer): ApiResponseModel
    {
        SocialNetworkThrottledException::throwIfThrottled($answer, 'The Mastodon instance asked for fewer requests.');
        if (\in_array($answer->status, self::REFUSALS, true)) {
            throw new SocialCredentialsRejectedException('The Mastodon instance refused this access token.');
        }
        if (!$answer->isSuccessful()) {
            throw new SocialNetworkUnreachableException(sprintf('The Mastodon instance answered %d.', $answer->status));
        }

        return $answer;
    }

    /** @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned */
    public function answer(ApiRequestModel $request): ApiResponseModel
    {
        try {
            return $this->api->send($request);
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException(
                'The Mastodon instance could not be reached.',
                previous: $exception,
            );
        }
    }
}
