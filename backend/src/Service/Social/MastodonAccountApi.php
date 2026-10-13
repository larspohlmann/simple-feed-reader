<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\MastodonSessionModel;

/** Calls an account's own Mastodon instance with its token; the caller reads the status, as with BlueskySessions. */
final readonly class MastodonAccountApi
{
    public function __construct(
        private MastodonApiClient $mastodon,
        private StoredCredential $storedCredential,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException when the stored token no longer opens
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public function get(SocialAccount $account, string $path): ApiResponseModel
    {
        $session = $this->sessionOf($account);

        return $this->mastodon->answer($session->get($session->url($path)));
    }

    /**
     * @throws SocialCredentialsRejectedException when the stored token no longer opens
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public function post(SocialAccount $account, string $path): ApiResponseModel
    {
        $session = $this->sessionOf($account);

        return $this->mastodon->answer($session->post($session->url($path)));
    }

    private function sessionOf(SocialAccount $account): MastodonSessionModel
    {
        return new MastodonSessionModel($account->getServiceUrl(), $this->storedCredential->open($account));
    }
}
