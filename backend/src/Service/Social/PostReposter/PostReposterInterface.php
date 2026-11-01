<?php

declare(strict_types=1);

namespace App\Service\Social\PostReposter;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.social_post_reposter')]
interface PostReposterInterface
{
    public static function network(): string;

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function repost(SocialPostModel $post): void;

    /**
     * A repost the network no longer has counts as undone.
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function unrepost(SocialAccount $account, string $repostId): void;
}
