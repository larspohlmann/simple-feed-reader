<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineReader;

use App\Entity\SocialFeed;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;

interface TimelineReaderInterface
{
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function page(SocialFeed $socialFeed): TimelinePageModel;
}
