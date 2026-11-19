<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Social\Model\SocialCredentialsModel;

final class LemmyCredentials
{
    /** @throws InvalidInstanceUrlException */
    public static function buildCredentials(
        string $instanceUrl,
        string $usernameOrEmail,
        #[\SensitiveParameter] string $password,
        ?string $secondFactor,
    ): SocialCredentialsModel {
        return new SocialCredentialsModel(
            SocialNetwork::Lemmy,
            InstanceUrl::requireOrigin($instanceUrl),
            $password,
            '',
            trim($usernameOrEmail),
            $secondFactor,
        );
    }

    public static function buildReconnectCredentials(
        SocialAccount $account,
        CredentialReplacementModel $replacement,
    ): SocialCredentialsModel {
        $identity = $account->getIdentity();

        return new SocialCredentialsModel(
            SocialNetwork::Lemmy,
            $account->getServiceUrl(),
            $replacement->secret,
            '',
            strstr($identity, '@', true) ?: $identity,
            $replacement->secondFactor,
        );
    }

    private function __construct()
    {
    }
}
