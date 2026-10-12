<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineSource;

use App\Entity\SocialFeed;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's private feeds, one call per poll, keyed by its SocialNetwork value in NetworkTimelineReader. */
#[AutoconfigureTag('app.social_timeline_source')]
interface TimelineSourceInterface
{
    public static function network(): string;

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function page(SocialFeed $socialFeed): TimelinePageModel;
}
