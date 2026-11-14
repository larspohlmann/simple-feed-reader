<?php

declare(strict_types=1);

namespace App\Service\Social\Social\SocialSearch;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;

interface SocialSearchInterface
{
    /**
     * @throws SearchQueryTooBroadException when Mastodon cannot run the query
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException once the account is marked as needing a reconnect
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function newest(SocialAccount $account, string $query): TimelinePageModel;
}
