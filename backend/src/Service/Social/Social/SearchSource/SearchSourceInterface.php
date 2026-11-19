<?php

declare(strict_types=1);

namespace App\Service\Social\Social\SearchSource;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's search, keyed by SocialNetwork; the caller already acts as the account. */
#[AutoconfigureTag('app.social_search_source')]
interface SearchSourceInterface
{
    /**
     * @throws SearchQueryTooBroadException when the network cannot run the query
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function fetchNewest(SocialAccount $account, string $query): TimelinePageModel;
}
