<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\ResponseKind;
use App\Service\Social\Social\Model\SocialPostModel;

/** A shared URL that names a post on the sharing account's own network is quoted, when the post lets it be. */
final readonly class SharedPostQuoteFinder
{
    public function __construct(
        private SocialPostFinder $posts,
        private SocialPostAddresses $postAddresses,
    ) {
    }

    /**
     * The post to quote instead of sharing the URL; null shares the URL as a link.
     *
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException
     */
    public function findQuotable(SocialAccount $account, string $url): ?SocialPostModel
    {
        $address = $this->postAddresses->parsePostUrl($url);
        if (null === $address || $address->network !== $account->getNetwork()) {
            return null;
        }

        try {
            $post = $this->posts->findAs($account, $address);
        } catch (SocialNetworkUnreachableException | SocialPostNotFoundException) {
            return null;
        }

        return $post->postContext->allows(ResponseKind::Quote) ? $post : null;
    }
}
