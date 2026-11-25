<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\PatientApiClient;
use App\Service\Social\Social\Support\NetworkAnswer;
use App\Service\Social\Social\Support\ServerFailure;
use Symfony\Component\HttpFoundation\Response;

final readonly class MastodonApiClient
{
    public const string VERIFY_CREDENTIALS = '/api/v1/accounts/verify_credentials';
    private const string NETWORK = 'The Mastodon instance';
    private const array REFUSALS = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];

    public function __construct(private PatientApiClient $api)
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
            throw new SocialNetworkUnreachableException('The Mastodon instance turned this request down.');
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

    /** @throws SocialNetworkUnreachableException when no answer arrived or it was a 5xx */
    public function answer(ApiRequestModel $request): ApiResponseModel
    {
        $answer = NetworkAnswer::fetchAnswer($request, $this->api->send(...), self::NETWORK);
        ServerFailure::throwIfServerError($request, $answer, $request->host());

        return $answer;
    }

    /** @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned */
    public static function requireAnswer(FetchOutcomeModel $outcome): ApiResponseModel
    {
        return NetworkAnswer::askNetwork($outcome->answerOrThrow(...), self::NETWORK);
    }
}
