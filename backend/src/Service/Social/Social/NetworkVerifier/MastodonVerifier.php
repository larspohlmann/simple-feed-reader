<?php

declare(strict_types=1);

namespace App\Service\Social\Social\NetworkVerifier;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\MastodonApiClient;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Social\Model\MastodonSessionModel;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Model\VerifiedAccountModel;
use App\Service\Social\Social\Support\MastodonCredentials;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonVerifier implements NetworkVerifierInterface
{
    public function __construct(private MastodonApiClient $mastodon)
    {
    }

    public function buildReconnectCredentials(
        SocialAccount $account,
        CredentialReplacementModel $replacement,
    ): SocialCredentialsModel {
        return MastodonCredentials::buildReconnectCredentials($account, $replacement);
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $session = new MastodonSessionModel($credentials->address, $credentials->secret);
        $answer = $this->mastodon->send($session->get($session->url(MastodonApiClient::VERIFY_CREDENTIALS)));

        $username = JsonNodeModel::of($answer->json())->string('username')
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance gave no account for this token.');
        $host = $session->host();

        return new VerifiedAccountModel(
            $username . '@' . $host,
            '@' . $username . '@' . $host,
            $credentials->address,
            $credentials->secret,
        );
    }
}
