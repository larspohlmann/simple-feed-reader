<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Content\Comments\CommentsLoader;
use App\Service\Library\Reading\EntryShareRecorder;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Model\SocialPostModel;

/** Reposts an entry's post or a reply in its thread; the network keeps the repost, and a repost of the entry's post shares it. */
final readonly class SocialReposts
{
    public function __construct(
        private SocialPostFinder $posts,
        private PostReposting $reposting,
        private SocialCountsCache $countsCache,
        private CommentsLoader $comments,
        private EntryShareRecorder $shares,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialPostRefusedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function repost(User $user, Entry $entry): void
    {
        $this->repostIfNot($this->posts->find($user, $entry));
        $this->countsCache->forgetEntry($entry);
        $this->shares->record($user, $entry);
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialPostRefusedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function unrepost(User $user, Entry $entry): void
    {
        $this->undoIfReposted($this->posts->find($user, $entry));
        $this->countsCache->forgetEntry($entry);
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialPostRefusedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function repostReply(User $user, Entry $entry, string $address): void
    {
        $this->repostIfNot($this->posts->findReply($user, $entry, $address));
        $this->comments->forget($user, $entry);
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialPostRefusedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function unrepostReply(User $user, Entry $entry, string $address): void
    {
        $this->undoIfReposted($this->posts->findReply($user, $entry, $address));
        $this->comments->forget($user, $entry);
    }

    private function repostIfNot(SocialPostModel $post): void
    {
        if (null !== $post->existingRepostId) {
            return;
        }
        if (!$post->canRepost) {
            throw new SocialPostRefusedException('The author keeps this post from being reposted.');
        }
        $this->reposting->repost($post);
    }

    private function undoIfReposted(SocialPostModel $post): void
    {
        if (null !== $post->existingRepostId) {
            $this->reposting->unrepost($post->account, $post->existingRepostId);
        }
    }
}
