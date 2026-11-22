<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Entry;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Content\Comments\CommentsLoader;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\Support\SocialIdentity;

/** Deletes a reply one of the user's connected accounts wrote, and forgets what the entry's caches held. */
final readonly class OwnReplyDeletion
{
    public function __construct(
        private SocialPostFinder $posts,
        private PostDeletions $deletions,
        private SocialAccountRepository $socialAccounts,
        private SocialCountsCache $countsCache,
        private CommentsLoader $comments,
        private SocialPostAddresses $postAddresses,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostRefusedException when no connected account wrote the reply
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function delete(User $user, Entry $entry, string $address): void
    {
        $ownReply = $this->findOwnReply($user, $this->postAddresses->requireReply($entry, $address));
        if (null !== $ownReply) {
            $this->deletions->delete($ownReply->account, $ownReply->postId);
        }
        $this->countsCache->forget($entry);
        $this->comments->forget($user, $entry);
    }

    /**
     * Asks every connected account, as one may fail to see a reply another wrote.
     *
     * @return ?SocialPostModel null when no account finds the reply any more, so it is already deleted
     */
    private function findOwnReply(User $user, SocialPostAddressModel $reply): ?SocialPostModel
    {
        $accounts = $this->socialAccounts->findConnectedOnNetwork($user, $reply->network);
        $failure = null;
        $writtenByAnother = [] === $accounts;
        foreach ($accounts as $account) {
            try {
                $post = $this->posts->findAs($account, $reply);
            } catch (SocialPostNotFoundException) {
                continue;
            } catch (
                SocialAccountNeedsReconnectException
                | SocialCredentialsRejectedException
                | SocialNetworkUnreachableException $exception
            ) {
                $failure ??= $exception;
                continue;
            }
            if (SocialIdentity::isAccount($post->postContext->authorIdentity, $account)) {
                return $post;
            }
            $writtenByAnother = true;
        }

        return match (true) {
            null !== $failure => throw $failure,
            $writtenByAnother => throw new SocialPostRefusedException('No connected account wrote this reply.'),
            default => null,
        };
    }
}
