<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\BlueskyPost\Support\BlueskyActorKey;
use App\Service\Social\Social\Exception\NotAnAppPasswordException;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Social\Model\SocialCredentialsModel;

final class BlueskyCredentials
{
    private const int HINT_LENGTH = 4;
    private const string APP_PASSWORD = '/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/';

    /** @throws NotAnAppPasswordException */
    public static function buildCredentials(
        string $handle,
        #[\SensitiveParameter] string $appPassword,
    ): SocialCredentialsModel {
        return self::buildCredentialsAt(BlueskyActorKey::of(ltrim(trim($handle), '@')), $appPassword);
    }

    /** @throws NotAnAppPasswordException */
    public static function buildReconnectCredentials(
        SocialAccount $account,
        CredentialReplacementModel $replacement,
    ): SocialCredentialsModel {
        return self::buildCredentialsAt($account->getIdentity(), $replacement->secret);
    }

    /** @throws NotAnAppPasswordException */
    private static function buildCredentialsAt(
        string $address,
        #[\SensitiveParameter] string $typedAppPassword,
    ): SocialCredentialsModel {
        $appPassword = trim($typedAppPassword);
        if (1 !== preg_match(self::APP_PASSWORD, $appPassword)) {
            throw new NotAnAppPasswordException('Use an app password, not your account password.');
        }

        return new SocialCredentialsModel(
            SocialNetwork::Bluesky,
            $address,
            $appPassword,
            substr($appPassword, -self::HINT_LENGTH),
        );
    }

    private function __construct()
    {
    }
}
