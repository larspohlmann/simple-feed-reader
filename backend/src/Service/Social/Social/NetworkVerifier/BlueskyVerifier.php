<?php

declare(strict_types=1);

namespace App\Service\Social\Social\NetworkVerifier;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\BlueskyIdentityResolver;
use App\Service\Social\Social\BlueskySessionOpener;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Model\VerifiedAccountModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyVerifier implements NetworkVerifierInterface
{
    public function __construct(
        private BlueskyIdentityResolver $identities,
        private BlueskySessionOpener $sessions,
    ) {
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $identity = $this->identities->resolve($credentials->address);
        $session = $this->sessions->open($identity, $credentials->secret);

        return new VerifiedAccountModel(
            $identity->did,
            '@' . $session->handle,
            $identity->pdsUrl,
            $credentials->secret,
        );
    }
}
