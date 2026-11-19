<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Support\InstanceUrl;

final class MastodonCredentials
{
    private const int HINT_LENGTH = 4;

    /** @throws InvalidInstanceUrlException */
    public static function buildCredentials(
        string $instanceUrl,
        #[\SensitiveParameter] string $accessToken,
    ): SocialCredentialsModel {
        return self::buildCredentialsAt(InstanceUrl::requireOrigin($instanceUrl), $accessToken);
    }

    public static function buildReconnectCredentials(
        SocialAccount $account,
        CredentialReplacementModel $replacement,
    ): SocialCredentialsModel {
        return self::buildCredentialsAt($account->getServiceUrl(), $replacement->secret);
    }

    private static function buildCredentialsAt(
        string $origin,
        #[\SensitiveParameter] string $typedToken,
    ): SocialCredentialsModel {
        $accessToken = trim($typedToken);

        return new SocialCredentialsModel(
            SocialNetwork::Mastodon,
            $origin,
            $accessToken,
            substr($accessToken, -self::HINT_LENGTH),
        );
    }

    private function __construct()
    {
    }
}
