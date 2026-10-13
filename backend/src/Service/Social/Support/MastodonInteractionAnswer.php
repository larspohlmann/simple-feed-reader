<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\MastodonApiClient;
use Symfony\Component\HttpFoundation\Response;

/** How a Mastodon instance answers an action on a post: only a 401 blames the token as a whole. */
final class MastodonInteractionAnswer
{
    private const string OUTSIDE_SCOPES = 'outside the authorized scopes';

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public static function accepted(ApiResponseModel $answer): ApiResponseModel
    {
        if (Response::HTTP_FORBIDDEN === $answer->status) {
            throw self::isOutsideScopes($answer)
                ? new SocialTokenScopeMissingException(
                    'The Mastodon access token of this account lacks the permission for this action.',
                )
                : new SocialPostRefusedException('Mastodon refused this action on the post.');
        }

        return MastodonApiClient::accepted($answer);
    }

    private static function isOutsideScopes(ApiResponseModel $answer): bool
    {
        return str_contains(JsonNodeModel::of($answer->json())->string('error') ?? '', self::OUTSIDE_SCOPES);
    }

    private function __construct()
    {
    }
}
