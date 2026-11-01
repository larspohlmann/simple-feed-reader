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
use App\Service\Social\Model\LinkShareModel;
use App\Service\Social\Model\LinkTargetModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Url\UrlNormalizer;

final readonly class LinkPosts
{
    public function __construct(
        private ActingAccountChooser $actingAccounts,
        private UrlNormalizer $urls,
        private PostWriting $writing,
        private SharedPostQuoteFinder $quotes,
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
        $share = $this->resolve($user, $target);

        return null === $share->quoted
            ? $this->contexts->ofLink($share->account, $share->link)
            : $this->contexts->ofQuote($share->quoted);
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
    public function share(User $user, LinkTargetModel $target, PostDraftModel $draft): PublishedPostModel
    {
        $share = $this->resolve($user, $target);

        return null === $share->quoted
            ? $this->writing->share($share->account, $share->link->url, $draft)
            : $this->writing->quote($share->quoted, $draft);
    }

    /**
     * @throws SocialPostRefusedException when the URL is no link to share
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException
     */
    private function resolve(User $user, LinkTargetModel $target): LinkShareModel
    {
        $url = $this->sharable($target->url);
        $account = $this->actingAccounts->chosenOrDefault($user, $target->accountId, $target->network);

        return new LinkShareModel(
            $account,
            new SharedLinkModel($url, $target->title),
            $this->quotes->quotableAt($account, $url),
        );
    }

    /** @throws SocialPostRefusedException */
    private function sharable(string $url): string
    {
        return $this->urls->normalize($url) ?? throw new SocialPostRefusedException('This is no link to share.');
    }
}
