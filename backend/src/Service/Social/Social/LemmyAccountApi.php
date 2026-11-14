<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\LemmySessionModel;

/** Calls an account's own Lemmy instance with its session token. */
final readonly class LemmyAccountApi
{
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
        return $this->lemmy->send($this->session($account)->get($pathAndQuery));
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

    private function session(SocialAccount $account): LemmySessionModel
    {
        return new LemmySessionModel($account->getServiceUrl(), $this->storedCredential->open($account));
    }
}
