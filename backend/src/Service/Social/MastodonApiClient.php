<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
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
     * @throws SocialNetworkUnreachableException
     */
    public function send(ApiRequestModel $request): ApiResponseModel
    {
        $answer = $this->transport($request);
        if (\in_array($answer->status, self::REFUSALS, true)) {
            throw new SocialCredentialsRejectedException('The Mastodon instance refused this access token.');
        }
        if (!$answer->isSuccessful()) {
            throw new SocialNetworkUnreachableException(sprintf('The Mastodon instance answered %d.', $answer->status));
        }

        return $answer;
    }

    private function transport(ApiRequestModel $request): ApiResponseModel
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
