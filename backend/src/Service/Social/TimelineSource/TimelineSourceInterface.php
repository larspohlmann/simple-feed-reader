<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineSource;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelinePageModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's private feeds: the request a poll sends and the page its answer maps to, keyed by SocialNetwork. */
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

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function request(SocialFeed $socialFeed): ApiRequestModel;

    /**
     * @throws SocialSessionStaleException when only a fresh session can read it
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function pageOf(SocialFeed $socialFeed, FetchOutcomeModel $outcome): TimelinePageModel;

    /** After a stale answer, so the account's next request opens a fresh session; a network without one keeps none. */
    public function dropSession(SocialAccount $account): void;
}
