<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Bluesky\Support\BlueskyActorKey;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\SocialPostAddress;

/** A shared URL that names a post on the sharing account's own network is quoted, when the post lets it be. */
final readonly class SharedPostQuotes
{
    public function __construct(
        private SocialPostFinder $posts,
        private BlueskyIdentityResolver $identities,
    ) {
    }

    /**
     * The post to quote instead of sharing the URL; null shares the URL as a link.
     *
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException
     */
    public function quotableAt(SocialAccount $account, string $url): ?SocialPostModel
    {
        $address = SocialPostAddress::ofUrl($url);
        if (null === $address || $address->network !== $account->getNetwork()) {
            return null;
        }

        try {
            $post = $this->posts->findAs($account, $this->canonical($address));
        } catch (
            SocialAccountUnresolvableException
            | SocialNetworkUnreachableException
            | SocialPostNotFoundException
        ) {
            return null;
        }

        return $post->responseContext->allows(ResponseKind::Quote) ? $post : null;
    }

    /**
     * A bsky.app address may name its author by handle; a quote's record names the post by its author's DID.
     *
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    private function canonical(SocialPostAddressModel $address): SocialPostAddressModel
    {
        $authority = AtPostUri::authorityOf($address->address);
        if (null === $authority || BlueskyActorKey::isDid($authority)) {
            return $address;
        }

        return new SocialPostAddressModel(
            SocialNetwork::Bluesky,
            AtPostUri::withAuthority($address->address, $this->identities->didOf($authority)),
        );
    }
}
