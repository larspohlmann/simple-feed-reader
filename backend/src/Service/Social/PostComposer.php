<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\User;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\ComposerContextModel;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\ResponseTargetModel;

final readonly class PostComposer
{
    public function __construct(
        private ResponseTargetFinder $targets,
        private ComposerContexts $contexts,
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
    public function contextFor(User $user, ResponseTargetModel $target, ResponseKind $kind): ComposerContextModel
    {
        return $this->contexts->ofResponse($this->targets->find($user, $target), $target, $kind);
    }
}
