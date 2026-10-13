<?php

declare(strict_types=1);

namespace App\Service\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's way from a post address to the post on an account, keyed by its SocialNetwork value. */
#[AutoconfigureTag('app.social_post_resolver')]
interface PostResolverInterface
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
    public function resolve(SocialAccount $account, string $address): SocialPostModel;
}
