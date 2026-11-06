<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Url\Support\UrlOrigin;

/** A Mastodon post on the reader's own instance, which resolves the post's address and redirects to its local copy. */
final class OwnInstancePostUrl
{
    private const string AUTHORIZE_INTERACTION = '%s/authorize_interaction?uri=%s';

    /**
     * @param list<SocialAccount> $accounts the reader's accounts on the post's network, the acting one first
     *
     * @return string|null null when the post is not Mastodon's, there is no account, or it lives on that instance
     */
    public static function of(SocialPostAddressModel $post, array $accounts): ?string
    {
        if (SocialNetwork::Mastodon !== $post->network || [] === $accounts) {
            return null;
        }
        $instance = UrlOrigin::canonical($accounts[0]->getServiceUrl());
        if (null === $instance || $instance === UrlOrigin::canonical($post->address)) {
            return null;
        }

        return sprintf(self::AUTHORIZE_INTERACTION, $instance, rawurlencode($post->address));
    }

    private function __construct()
    {
    }
}
