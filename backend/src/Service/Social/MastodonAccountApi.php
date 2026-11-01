<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\MastodonSessionModel;
use Symfony\Component\HttpFoundation\Response;

/** Calls an account's own Mastodon instance with its token. */
final readonly class MastodonAccountApi
{
    private const string OUTSIDE_SCOPES = 'outside the authorized scopes';
    private const string READ_SCOPE = 'read';

    public function __construct(
        private MastodonApiClient $mastodon,
        private StoredCredential $storedCredential,
    ) {
    }

    /** @throws SocialCredentialsRejectedException when the stored token no longer opens */
    public function authorizedGet(SocialAccount $account, string $path): ApiRequestModel
    {
        return $this->authorized($account, ApiRequestModel::get($account->getServiceUrl() . $path));
    }

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException only on a 401; a 403 concerns the post or the token's scope
     * @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function askAboutPost(SocialAccount $account, string $path): ApiResponseModel
    {
        $request = ApiRequestModel::get($account->getServiceUrl() . $path);

        return self::postAnswerAccepted($this->answer($account, $request), self::READ_SCOPE);
    }

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException only on a 401; a 403 concerns the post or the token's scope
     * @throws SocialTokenScopeMissingException naming $scope
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function actOnPost(SocialAccount $account, string $path, string $scope): ApiResponseModel
    {
        $request = ApiRequestModel::post($account->getServiceUrl() . $path);

        return self::postAnswerAccepted($this->answer($account, $request), $scope);
    }

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException only on a 401; a 403 concerns the post or the token's scope
     * @throws SocialTokenScopeMissingException naming $scope
     * @throws SocialPostRefusedException carrying Mastodon's reason when it rejects what was sent
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function writeAs(SocialAccount $account, ApiRequestModel $request, string $scope): ApiResponseModel
    {
        $answer = $this->answer($account, $request);
        if (Response::HTTP_UNPROCESSABLE_ENTITY === $answer->status) {
            throw new SocialPostRefusedException(
                JsonNodeModel::of($answer->json())->string('error') ?? 'Mastodon refused what was sent.',
            );
        }

        return self::postAnswerAccepted($answer, $scope);
    }

    private function answer(SocialAccount $account, ApiRequestModel $request): ApiResponseModel
    {
        return $this->mastodon->answer($this->authorized($account, $request));
    }

    private function authorized(SocialAccount $account, ApiRequestModel $request): ApiRequestModel
    {
        $session = new MastodonSessionModel($account->getServiceUrl(), $this->storedCredential->open($account));

        return $session->authorized($request);
    }

    private static function postAnswerAccepted(ApiResponseModel $answer, string $scope): ApiResponseModel
    {
        if (Response::HTTP_NOT_FOUND === $answer->status) {
            throw new SocialPostNotFoundException('The post is no longer on Mastodon.');
        }
        if (Response::HTTP_FORBIDDEN === $answer->status) {
            throw self::isOutsideScopes($answer)
                ? new SocialTokenScopeMissingException(
                    'The Mastodon access token of this account lacks the permission for this action.',
                    $scope,
                )
                : new SocialPostRefusedException('Mastodon refused this action on the post.');
        }

        return MastodonApiClient::accepted($answer);
    }

    private static function isOutsideScopes(ApiResponseModel $answer): bool
    {
        return str_contains(JsonNodeModel::of($answer->json())->string('error') ?? '', self::OUTSIDE_SCOPES);
    }
}
