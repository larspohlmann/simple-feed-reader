<?php

declare(strict_types=1);

namespace App\Service\Social\NetworkVerifier;

use App\Enum\SocialNetwork;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;

final readonly class BlueskyVerifier implements NetworkVerifierInterface
{
    private const string CREATE_SESSION = '/xrpc/com.atproto.server.createSession';

    public function __construct(
        private BlueskyIdentityResolver $identities,
        private GuardedApiClient $api,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $identity = $this->identities->resolve($credentials->address);

        try {
            $answer = $this->api->send(ApiRequestModel::postJson(
                $identity->pdsUrl . self::CREATE_SESSION,
                ['identifier' => $identity->did, 'password' => $credentials->secret],
            ));
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException(
                'The Bluesky server could not be reached.',
                previous: $exception,
            );
        }

        $session = $answer->json();
        $handle = $session['handle'] ?? null;
        if (!$answer->isSuccessful() || ($session['did'] ?? null) !== $identity->did || !\is_string($handle)) {
            throw new SocialCredentialsRejectedException('Bluesky refused this handle and app password.');
        }

        return new VerifiedAccountModel($identity->did, '@' . $handle, $identity->pdsUrl);
    }
}
