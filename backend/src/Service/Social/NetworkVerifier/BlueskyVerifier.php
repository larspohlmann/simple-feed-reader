<?php

declare(strict_types=1);

namespace App\Service\Social\NetworkVerifier;

use App\Enum\SocialNetwork;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;

final readonly class BlueskyVerifier implements NetworkVerifierInterface
{
    public function __construct(
        private BlueskyIdentityResolver $identities,
        private BlueskySessionOpener $sessions,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $identity = $this->identities->resolve($credentials->address);
        $session = $this->sessions->open($identity, $credentials->secret);

        return new VerifiedAccountModel($identity->did, '@' . $session->handle, $identity->pdsUrl);
    }
}
