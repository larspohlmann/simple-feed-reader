<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Content\Comments\CommentsLoader;
use App\Service\Library\Reading\EntryShareRecorder;
use App\Service\Social\Publishing\Exception\SocialPostTooLongException;
use App\Service\Social\Publishing\Model\ComposerContextModel;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Publishing\Model\ResponseTargetModel;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Model\ResponseKind;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\SocialCountsCache;

/** Opens the composer for the user's replies and quotes, and publishes them. */
final readonly class SocialResponses
{
    public function __construct(
        private ResponseTargetFinder $targets,
        private ComposerContexts $contexts,
        private PostWriting $writing,
        private SocialCountsCache $countsCache,
        private CommentsLoader $comments,
        private EntryShareRecorder $shares,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function prepareContext(User $user, ResponseTargetModel $target, ResponseKind $kind): ComposerContextModel
    {
        $post = $this->targets->find($user, $target);

        return match ($kind) {
            ResponseKind::Reply => $this->contexts->buildReplyContext($post, $target),
            ResponseKind::Quote => $this->contexts->buildQuoteContext($post),
        };
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialPostRefusedException
     * @throws SocialPostTooLongException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function respond(
        User $user,
        ResponseTargetModel $target,
        ResponseKind $kind,
        PostDraftModel $draft,
    ): PublishedPostModel {
        $published = $this->writing->respond($kind, $this->allowedTarget($user, $target, $kind), $draft);
        $this->forgetWhatChanged($user, $target->entry);
        if (ResponseKind::Quote === $kind && null === $target->address) {
            $this->shares->record($user, $target->entry);
        }

        return $published;
    }

    /** @throws SocialPostRefusedException when the author does not allow this kind of response to the post */
    private function allowedTarget(User $user, ResponseTargetModel $target, ResponseKind $kind): SocialPostModel
    {
        $post = $this->targets->find($user, $target);
        if (!$post->postContext->allows($kind)) {
            throw new SocialPostRefusedException(sprintf('The author does not allow a %s of this post.', $kind->value));
        }

        return $post;
    }

    private function forgetWhatChanged(User $user, Entry $entry): void
    {
        $this->countsCache->forget($entry);
        $this->comments->forget($user, $entry);
    }
}
