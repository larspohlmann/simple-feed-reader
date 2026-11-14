<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostWriter;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\PublishedPostModel;
use App\Service\Social\Social\Model\PostDraftModel;
use App\Service\Social\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.social_post_writer')]
interface PostWriterInterface
{
    /**
     * @throws SocialPostTooLongException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel;

    /**
     * @throws SocialPostTooLongException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel;

    /**
     * @throws SocialPostTooLongException
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function share(SocialAccount $account, string $url, PostDraftModel $draft): PublishedPostModel;

    /**
     * @return PostDeletion AlreadyGone when the network no longer has the post
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function delete(SocialAccount $account, string $postId): PostDeletion;
}
