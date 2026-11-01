<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\User;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\ResponseTargetModel;

/** Publishes the user's quotes of the entry's post or of a reply in its thread. */
final readonly class SocialQuotes
{
    public function __construct(
        private ResponseTargetFinder $targets,
        private PostWriting $writing,
        private SocialCountsCache $countsCache,
    ) {
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
    public function quote(User $user, ResponseTargetModel $target, PostDraftModel $draft): PublishedPostModel
    {
        $post = $this->targets->find($user, $target);
        if (!$post->responseContext->canQuote) {
            throw new SocialPostRefusedException('The author does not allow quoting this post.');
        }

        $published = $this->writing->quote($post, $draft);
        $this->countsCache->forgetEntry($target->entry);

        return $published;
    }
}
