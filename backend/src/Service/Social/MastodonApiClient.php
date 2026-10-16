<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Support\NetworkAnswer;
use Symfony\Component\HttpFoundation\Response;

final readonly class MastodonApiClient
{
    private const string NETWORK = 'The Mastodon instance';
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

    /**
     * @param \Closure(string): \RuntimeException $closedToThePublic builds what a 401 or 403 throws
     *
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public static function acceptedPublicStatus(ApiResponseModel $answer, \Closure $closedToThePublic): ApiResponseModel
    {
        if (Response::HTTP_NOT_FOUND === $answer->status) {
            throw new SocialPostNotFoundException('The post is no longer on its Mastodon instance.');
        }
        if (\in_array($answer->status, self::REFUSALS, true)) {
            throw $closedToThePublic('The Mastodon instance shows its statuses to accounts only.');
        }

        return self::accepted($answer);
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
