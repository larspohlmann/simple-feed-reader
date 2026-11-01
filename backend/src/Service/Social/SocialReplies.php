<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Comments\CommentsLoader;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialReplyTooLongException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\PublishedReplyModel;
use App\Service\Social\Model\ReplyDraftModel;
use App\Service\Social\Model\ReplyTargetModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\SocialIdentity;
use App\Service\Social\Support\SocialPostAddress;

/** Publishes the user's replies and deletes the ones a connected account wrote. */
final readonly class SocialReplies
{
    public function __construct(
        private ReplyTargetFinder $targets,
        private SocialPostFinder $posts,
        private PostReplying $replying,
        private SocialAccountRepository $socialAccounts,
        private SocialCountsCache $countsCache,
        private CommentsLoader $comments,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialPostRefusedException
     * @throws SocialReplyTooLongException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function reply(User $user, ReplyTargetModel $target, ReplyDraftModel $draft): PublishedReplyModel
    {
        $post = $this->targets->find($user, $target);
        if (!$post->replyContext->canReply) {
            throw new SocialPostRefusedException('The author does not take replies on this post.');
        }

        $published = $this->replying->publish($post, $draft);
        $this->forgetWhatChanged($user, $target->entry);

        return $published;
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
        $ownReply = $this->ownReplyAt($user, SocialPostAddress::replyOrRefuse($entry, $address));
        if (null !== $ownReply) {
            $this->replying->delete($ownReply);
        }
        $this->forgetWhatChanged($user, $entry);
    }

    /**
     * Asks every connected account, as one may fail to see a reply another wrote.
     *
     * @return ?SocialPostModel null when no account finds the reply any more, so it is already deleted
     */
    private function ownReplyAt(User $user, SocialPostAddressModel $reply): ?SocialPostModel
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
            if (SocialIdentity::isAccount($post->replyContext->authorIdentity, $account)) {
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

    private function forgetWhatChanged(User $user, Entry $entry): void
    {
        $this->countsCache->forgetEntry($entry);
        $this->comments->forget($user, $entry);
    }
}
