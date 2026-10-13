<?php

declare(strict_types=1);

namespace App\Service\Social\PostCountsSource;

use App\Service\Social\Exception\PostNotPubliclyCountableException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialCountsModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's public, account-free way to a post's counts, keyed by its SocialNetwork value. */
#[AutoconfigureTag('app.social_post_counts_source')]
interface PostCountsSourceInterface
{
    public static function network(): string;

    /**
     * @throws PostNotPubliclyCountableException
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function countsOf(string $address): SocialCountsModel;
}
