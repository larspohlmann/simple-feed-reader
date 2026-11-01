<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\User;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\ComposerContextModel;
use App\Service\Social\Model\LinkPostModel;
use App\Service\Social\Model\LinkTargetModel;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Url\UrlNormalizer;

final readonly class LinkPosts
{
    public function __construct(
        private ActingAccountChooser $actingAccounts,
        private UrlNormalizer $urls,
        private PostWriting $writing,
        private SharedPostQuoteFinder $quotes,
        private SharedPageCards $pageCards,
        private ComposerContexts $contexts,
    ) {
    }

    /**
     * @throws SocialPostRefusedException when the URL is no link to share
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException
     */
    public function contextFor(User $user, LinkTargetModel $target): ComposerContextModel
    {
        $url = $this->sharable($target->url);
        $account = $this->actingAccounts->chosenOrDefault($user, $target->accountId, $target->network);
        $quoted = $this->quotes->quotableAt($account, $url);

        return null === $quoted
            ? $this->contexts->ofLink($account, $this->pageCards->cardOf($url))
            : $this->contexts->ofQuote($quoted);
    }

    /**
     * Quotes the post the URL names on the network it goes to, else shares the URL as a link.
     *
     * @throws SocialPostRefusedException when the URL is no link to share
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostTooLongException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function share(User $user, LinkPostModel $post): PublishedPostModel
    {
        $url = $this->sharable($post->target->url);
        $account = $this->actingAccounts->chosenOrDefault($user, $post->target->accountId, $post->target->network);
        $quoted = $this->quotes->quotableAt($account, $url);

        return null === $quoted
            ? $this->writing->share($account, $url, $post->draft)
            : $this->writing->quote($quoted, $post->draft);
    }

    /** @throws SocialPostRefusedException */
    private function sharable(string $url): string
    {
        return $this->urls->normalize($url) ?? throw new SocialPostRefusedException('This is no link to share.');
    }
}
