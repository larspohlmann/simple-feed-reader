<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\AccountVerifier\AccountVerifierInterface;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Model\VerifiedAccountModel;
use App\Service\Social\Social\Support\BlueskyCredentials;
use App\Service\Social\Social\Support\LemmyCredentials;
use App\Service\Social\Social\Support\MastodonCredentials;

final class StubAccountVerifier implements AccountVerifierInterface
{
    public ?SocialCredentialsModel $lastCredentials = null;

    public function __construct(private readonly VerifiedAccountModel|\Throwable $outcome)
    {
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $this->lastCredentials = $credentials;
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }

    public function buildReconnectCredentials(
        SocialAccount $account,
        CredentialReplacementModel $replacement,
    ): SocialCredentialsModel {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => BlueskyCredentials::buildReconnectCredentials($account, $replacement),
            SocialNetwork::Mastodon => MastodonCredentials::buildReconnectCredentials($account, $replacement),
            SocialNetwork::Lemmy => LemmyCredentials::buildReconnectCredentials($account, $replacement),
        };
    }
}
