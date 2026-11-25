<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Lemmy\Model\LemmySessionModel;
use App\Service\Social\Lemmy\Support\LemmySearchPath;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\StoredCredential;

final readonly class LemmyAccountApi
{
    private const float SEARCH_TIMEOUT_SECONDS = 25.0;
    private const float POST_CREATION_TIMEOUT_SECONDS = 8.0;

    public function __construct(
        private LemmyApiClient $lemmy,
        private StoredCredential $storedCredential,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function get(SocialAccount $account, string $pathAndQuery): ApiResponseModel
    {
        return $this->lemmy->send($this->authorizedGet($account, $pathAndQuery));
    }

    /** @throws SocialCredentialsRejectedException when the stored token no longer opens */
    public function authorizedGet(SocialAccount $account, string $pathAndQuery): ApiRequestModel
    {
        return $this->session($account)->get($pathAndQuery);
    }

    /** @throws SocialCredentialsRejectedException when the stored token no longer opens */
    public function searchGet(SocialAccount $account, string $term): ApiRequestModel
    {
        return $this->authorizedGet($account, LemmySearchPath::buildPath($term))
            ->waitingUpTo(self::SEARCH_TIMEOUT_SECONDS);
    }

    /**
     * @param array<string, mixed> $json
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function post(SocialAccount $account, string $path, array $json): ApiResponseModel
    {
        return $this->lemmy->send($this->session($account)->postJson($path, $json));
    }

    /**
     * @param array<string, mixed> $json
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function publishPost(SocialAccount $account, array $json): ApiResponseModel
    {
        return $this->lemmy->send(
            $this->session($account)->postJson('/api/v3/post', $json)
                ->waitingUpTo(self::POST_CREATION_TIMEOUT_SECONDS),
        );
    }

    private function session(SocialAccount $account): LemmySessionModel
    {
        return new LemmySessionModel($account->getServiceUrl(), $this->storedCredential->open($account));
    }
}
