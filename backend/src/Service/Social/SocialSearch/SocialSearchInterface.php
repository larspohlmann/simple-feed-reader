<?php

declare(strict_types=1);

namespace App\Service\Social\SocialSearch;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;

interface SocialSearchInterface
{
    /**
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException once the account is marked as needing a reconnect
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function newest(SocialAccount $account, string $query): TimelinePageModel;
}
