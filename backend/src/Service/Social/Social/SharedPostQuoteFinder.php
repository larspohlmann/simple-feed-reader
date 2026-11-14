<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\SocialPostAddress;

/** A shared URL that names a post on the sharing account's own network is quoted, when the post lets it be. */
final readonly class SharedPostQuoteFinder
{
    public function __construct(private SocialPostFinder $posts)
    {
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
            $post = $this->posts->findAs($account, $address);
        } catch (SocialNetworkUnreachableException | SocialPostNotFoundException) {
            return null;
        }

        return $post->responseContext->allows(ResponseKind::Quote) ? $post : null;
    }
}
