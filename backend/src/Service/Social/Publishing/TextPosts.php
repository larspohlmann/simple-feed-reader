<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Social\Publishing\Exception\SocialPostTooLongException;
use App\Service\Social\Publishing\Model\ComposerContextModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Publishing\Model\TextPostTargetModel;
use App\Service\Social\Social\ActingAccountChooser;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;

/** A post of text alone, answering nothing and sharing nothing. */
final readonly class TextPosts
{
    public function __construct(
        private ActingAccountChooser $actingAccounts,
        private PostWriting $writing,
        private ComposerContexts $contexts,
    ) {
    }

    /**
     * @throws SocialPostRefusedException on Lemmy, whose posts need a title and a community
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException
     */
    public function prepareContext(User $user, TextPostTargetModel $target): ComposerContextModel
    {
        return $this->contexts->buildTextPostContext($this->requireAuthor($user, $target));
    }

    /**
     * @throws SocialPostRefusedException on Lemmy, whose posts need a title and a community
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostTooLongException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function publish(User $user, TextPostTargetModel $target, PublicationModel $publication): PublishedPostModel
    {
        return $this->writing->publish($this->requireAuthor($user, $target), $publication);
    }

    /**
     * @throws SocialPostRefusedException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     */
    private function requireAuthor(User $user, TextPostTargetModel $target): SocialAccount
    {
        if (SocialNetwork::Lemmy === $target->network) {
            throw new SocialPostRefusedException('Lemmy takes no text post here.');
        }

        return $this->actingAccounts->chosenOrDefault($user, $target->accountId, $target->network);
    }
}
