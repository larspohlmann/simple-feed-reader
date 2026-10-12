<?php

declare(strict_types=1);

namespace App\Service\Social\NetworkVerifier;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\MastodonSessionModel;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;

final readonly class MastodonVerifier implements NetworkVerifierInterface
{
    private const string VERIFY_CREDENTIALS = '/api/v1/accounts/verify_credentials';

    public function __construct(private MastodonApiClient $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $session = new MastodonSessionModel($credentials->address, $credentials->secret);
        $answer = $this->mastodon->send($session->get($session->url(self::VERIFY_CREDENTIALS)));

        $username = JsonNodeModel::of($answer->json())->string('username')
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance gave no account for this token.');
        $host = $session->host();

        return new VerifiedAccountModel($username . '@' . $host, '@' . $username . '@' . $host, $credentials->address);
    }
}
